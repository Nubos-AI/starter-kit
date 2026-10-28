<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Scopes\TeamRecordAccessScope;
use App\Support\Authorization\RowAccess\AccessRuleFieldSource;
use App\Support\Authorization\RowAccess\EffectiveRecordAccessRules;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAccessRuleFieldSource;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->companies = ModelStub::ulid('companies');
    $this->contacts = ModelStub::ulid('contacts');

    $postalCode = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-postal-code'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->companies,
        'key' => 'postal_code',
        'field_type' => FieldType::TextShort,
        'is_filterable' => true,
        'is_translatable' => false,
        'is_encrypted' => false,
    ]);

    app()->instance(AccessRuleFieldSource::class, new StaticAccessRuleFieldSource([
        $this->companies => new Collection([$postalCode]),
    ]));

    /** @var callable(string):array<string, mixed> */
    $this->prefixTree = static fn (string $prefix): array => [
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => $prefix],
        ],
    ];

    /** @var callable(array<string, list<list<array<string, mixed>>>>):QueryShape */
    $this->compiled = function (array $byObjectType): QueryShape {
        $query = CustomRecord::query()->withoutGlobalScope(TeamRecordAccessScope::class);

        app(RecordAccessRuleCompiler::class)->apply(
            $query,
            new EffectiveRecordAccessRules($byObjectType),
            'custom_records',
        );

        return QueryShape::of($query);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('leaves the query untouched when the effective rules are unrestricted', function (): void {
    $shape = ($this->compiled)([]);

    expect($shape->sql)->not->toContain('object_type_id')
        ->and($shape->bindings)->toBe([(string) $this->tenant->getKey()]);
});

it('keeps every object type the rules do not name fully visible', function (): void {
    $shape = ($this->compiled)([$this->companies => [[($this->prefixTree)('76')]]]);

    expect($shape->sql)->toContain('"custom_records"."object_type_id" not in (?)')
        ->and($shape->hasBinding($this->companies))->toBeTrue();
});

it('narrows a named object type by the filter the rule declares', function (): void {
    $shape = ($this->compiled)([$this->companies => [[($this->prefixTree)('76')]]]);

    expect($shape->sql)->toContain("((data->>'postal_code') ILIKE ?")
        ->and($shape->bindings)->toContain('76%');
});

it('wraps the whole rule set in one group so it cannot be broken apart', function (): void {
    $shape = ($this->compiled)([$this->companies => [[($this->prefixTree)('76')]]]);

    expect($shape->sql)->toContain('where ("custom_records"."object_type_id" not in (?) or ("custom_records"."object_type_id" = ?')
        ->and($shape->sql)->toContain(')) and "custom_records"."deleted_at" is null');
});

it('blocks every row of a type whose rule names a field that no longer exists', function (): void {
    $shape = ($this->compiled)([$this->companies => [[[
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'geloeschtes_feld', 'operator' => 'equals', 'value' => 'x'],
        ],
    ]]]]);

    expect($shape->blocksEveryRow())->toBeTrue()
        ->and($shape->sql)->not->toContain('geloeschtes_feld');
});

it('blocks only the type whose field is gone and keeps the other type filtered', function (): void {
    $shape = ($this->compiled)([
        $this->companies => [[($this->prefixTree)('76')]],
        $this->contacts => [[[
            'combinator' => 'and',
            'conditions' => [
                ['field' => 'geloeschtes_feld', 'operator' => 'equals', 'value' => 'x'],
            ],
        ]]],
    ]);

    expect($shape->blocksEveryRow())->toBeTrue()
        ->and($shape->bindings)->toContain('76%')
        ->and($shape->hasBinding($this->contacts))->toBeTrue()
        ->and(substr_count($shape->sql, '1 = 0'))->toBe(1);
});

it('combines the trees of one chain with and so a parent rule keeps narrowing', function (): void {
    $shape = ($this->compiled)([$this->companies => [[
        ($this->prefixTree)('761'),
        ($this->prefixTree)('76'),
    ]]]);

    expect($shape->bindings)->toContain('761%')
        ->and($shape->bindings)->toContain('76%')
        ->and($shape->sql)->not->toContain('OR (data');
});

it('combines the chains of several memberships with or so neither membership is lost', function (): void {
    $shape = ($this->compiled)([$this->companies => [
        [($this->prefixTree)('76')],
        [($this->prefixTree)('80')],
    ]]);

    expect($shape->bindings)->toContain('76%')
        ->and($shape->bindings)->toContain('80%')
        ->and(substr_count($shape->sql, 'ILIKE ?'))->toBe(2);
});

it('asks the field source once per object type and remembers the answer', function (): void {
    $source = app(AccessRuleFieldSource::class);

    ($this->compiled)([$this->companies => [
        [($this->prefixTree)('76')],
        [($this->prefixTree)('80')],
    ]]);

    expect($source)->toBeInstanceOf(StaticAccessRuleFieldSource::class)
        ->and($source->askedFor)->toBe([$this->companies]);
});
