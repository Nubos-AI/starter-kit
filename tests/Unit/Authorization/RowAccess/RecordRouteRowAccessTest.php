<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\TeamRecordAccessRule;
use App\Support\Authorization\RowAccess\AccessRuleFieldSource;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Authorization\RowAccess\TeamChainProvider;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAccessRuleFieldSource;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\Doubles\StaticTeamChainProvider;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->team = AccessContext::team($this->tenant);

    $this->companies = ModelStub::ulid('companies');
    $this->hiddenRecord = ModelStub::ulid('hidden-record');

    app()->instance(AccessRuleFieldSource::class, new StaticAccessRuleFieldSource([
        $this->companies => new Collection([
            ModelStub::make(FieldDefinition::class, [
                'id' => ModelStub::ulid('field-postal-code'),
                'tenant_id' => $this->tenant->getKey(),
                'object_type_id' => $this->companies,
                'key' => 'postal_code',
                'field_type' => FieldType::TextShort,
                'is_filterable' => true,
                'is_translatable' => false,
                'is_encrypted' => false,
            ]),
        ]),
    ]));

    app()->instance(TeamChainProvider::class, new StaticTeamChainProvider([[(string) $this->team->getKey()]]));
    app()->instance(TeamAccessRuleSource::class, new StaticTeamAccessRuleSource(new EloquentCollection([
        ModelStub::make(TeamRecordAccessRule::class, [
            'id' => ModelStub::ulid('rule'),
            'tenant_id' => $this->tenant->getKey(),
            'team_id' => $this->team->getKey(),
            'object_type_id' => $this->companies,
            'is_active' => true,
            'inheritance' => 'intersect',
            'filter_definition' => [
                'combinator' => 'and',
                'conditions' => [
                    ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '76'],
                ],
            ],
        ]),
    ])));

    app()->forgetInstance(TeamAccessRuleResolver::class);
    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

    $this->member = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('member'),
        'tenant_id' => $this->tenant->getKey(),
        'current_team_id' => $this->team->getKey(),
    ]);

    AccessContext::actAs($this->member);
    AccessContext::enforceRowAccess();

    /** @var callable(string, string):?QueryShape */
    $this->bindingQueryOf = fn (string $routeName, string $uri): ?QueryShape => QueryShape::attemptedBy(
        fn (): mixed => MiddlewarePipeline::forRoute($routeName, [
            EnforceRecordAccessRules::class,
            SubstituteBindings::class,
        ])->send(
            RouteShape::named($routeName)->request($uri),
            static fn (): Response => new Response('reached'),
        ),
    );
});

afterEach(function (): void {
    app(RowAccessEnforcement::class)->disable();
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

it('compiles the team rule into every record query the scope touches', function (): void {
    $shape = QueryShape::of(CustomRecord::query());

    expect($shape->sql)->toContain("((data->>'postal_code') ILIKE ?")
        ->and($shape->bindings)->toContain('76%')
        ->and($shape->sql)->toContain('"custom_records"."object_type_id" not in (?)')
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps an or filter of the caller inside its own group so it cannot widen the rule', function (): void {
    $shape = QueryShape::of(
        CustomRecord::query()
            ->where('id', ModelStub::ulid('visible-record'))
            ->orWhere('id', $this->hiddenRecord),
    );

    expect($shape->sql)->toContain('where ("id" = ? or "id" = ?) and "custom_records"."deleted_at" is null')
        ->and($shape->sql)->toContain('and ("custom_records"."object_type_id" not in (?) or (')
        ->and($shape->bindings)->toContain('76%');
});

it('resolves the record of the file list through a query the team rule already narrows', function (): void {
    $shape = ($this->bindingQueryOf)('files.index', '/'.$this->team->getKey().'/records/'.$this->hiddenRecord.'/files');

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasBinding($this->hiddenRecord))->toBeTrue()
        ->and($shape->bindings)->toContain('76%');
});

it('resolves the record of the note list through a query the team rule already narrows', function (): void {
    $shape = ($this->bindingQueryOf)('notes.index', '/'.$this->team->getKey().'/records/'.$this->hiddenRecord.'/notes');

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->hiddenRecord))->toBeTrue()
        ->and($shape->bindings)->toContain('76%');
});

it('resolves the record of the merge page through a query the team rule already narrows', function (): void {
    $shape = ($this->bindingQueryOf)('engine.records.merge.show', '/'.$this->team->getKey().'/records/'.$this->hiddenRecord.'/merge');

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->hiddenRecord))->toBeTrue()
        ->and($shape->bindings)->toContain('76%');
});

it('resolves the record of the relation list through a query the team rule already narrows', function (): void {
    $shape = ($this->bindingQueryOf)('engine.records.relations.index', '/'.$this->team->getKey().'/engine/records/'.$this->hiddenRecord.'/relations');

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->hiddenRecord))->toBeTrue()
        ->and($shape->bindings)->toContain('76%');
});

it('turns enforcement on before the binding runs on every one of those routes', function (): void {
    $routes = ['files.index', 'files.show', 'notes.index', 'engine.records.merge.show', 'engine.records.merge.store', 'engine.records.relations.index'];

    expect(array_map(
        static fn (string $name): bool => RouteShape::named($name)->runsBefore(EnforceRecordAccessRules::class, SubstituteBindings::class),
        $routes,
    ))->toBe(array_fill(0, count($routes), true));
});
