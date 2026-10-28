<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Handlers\Timeline\MergeTimelineSource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\TeamRecordAccessRule;
use App\Models\TimelineEntry;
use App\Support\Authorization\RowAccess\AccessRuleFieldSource;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Authorization\RowAccess\TeamChainProvider;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticAccessRuleFieldSource;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\Doubles\StaticTeamChainProvider;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->team = AccessContext::team($this->tenant);

    $this->companies = ModelStub::ulid('companies');
    $this->hiddenCounterpart = ModelStub::ulid('hidden-counterpart');

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

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

    $this->member = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('member'),
        'tenant_id' => $this->tenant->getKey(),
        'current_team_id' => $this->team->getKey(),
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('subject-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->companies,
    ], ['objectType' => ModelStub::make(ObjectType::class, [
        'id' => $this->companies,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ])]);

    /** @var callable(?string):Collection<int, TimelineEntry> */
    $this->entries = fn (?string $counterpartId): Collection => new Collection([
        ModelStub::make(TimelineEntry::class, [
            'id' => ModelStub::ulid('entry'),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => $this->record->getKey(),
            'source_key' => 'merge',
            'payload' => [
                'direction' => 'absorbed',
                'counterpart_id' => $counterpartId,
                'counterpart_number' => 'GEHEIM-1',
            ],
        ]),
    ]);

    AccessContext::actAs($this->member);
    AccessContext::enforceRowAccess();
});

afterEach(function (): void {
    app(RowAccessEnforcement::class)->disable();
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

it('looks up the hidden counterparts by subtracting the row access scoped set from the unscoped one', function (): void {
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(fn (): mixed => app(MergeTimelineSource::class)->filterVisible(
        $this->member,
        $this->record,
        ($this->entries)($this->hiddenCounterpart),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('"id" not in (select "id" from "custom_records"')
        ->and($shape->hasBinding($this->hiddenCounterpart))->toBeTrue();
});

it('applies the team rule to the visible set only, never to the set it compares against', function (): void {
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(fn (): mixed => app(MergeTimelineSource::class)->filterVisible(
        $this->member,
        $this->record,
        ($this->entries)($this->hiddenCounterpart),
    ));

    expect($shape->bindings)->toContain('76%')
        ->and(substr_count($shape->sql, 'ILIKE ?'))->toBe(1);
});

it('never asks the database when the viewer may not see the record at all', function (): void {
    GateSpy::allowing();

    $visible = null;

    $shape = QueryShape::attemptedBy(function () use (&$visible): mixed {
        return $visible = app(MergeTimelineSource::class)->filterVisible(
            $this->member,
            $this->record,
            ($this->entries)($this->hiddenCounterpart),
        );
    });

    expect($shape)->toBeNull()
        ->and($visible)->toHaveCount(0);
});

it('never asks the database when no entry names a counterpart', function (): void {
    GateSpy::allowing('view');

    $shape = QueryShape::attemptedBy(fn (): mixed => app(MergeTimelineSource::class)->filterVisible(
        $this->member,
        $this->record,
        ($this->entries)(null),
    ));

    expect($shape)->toBeNull();
});
