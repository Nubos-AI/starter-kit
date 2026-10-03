<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->relationshipTypeId = ModelStub::ulid('compiler-relationship-type');

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $config = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('compiler-field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('compiler-object-type'),
            'key' => $key,
            'field_type' => $type,
            'is_filterable' => true,
            'is_sortable' => true,
            'is_encrypted' => false,
            'is_translatable' => false,
            'config' => $config,
        ],
    );

    /** @var Collection<int, FieldDefinition> */
    $this->fields = new Collection([
        ($this->field)('status', FieldType::TextShort),
        ($this->field)('stufe', FieldType::TextShort),
        ($this->field)('stadt', FieldType::TextShort),
        ($this->field)('betrag', FieldType::Money),
        ($this->field)('partner', FieldType::RelationHasMany, ['relationship_type_id' => $this->relationshipTypeId]),
    ]);

    /** @var callable(array<string, mixed>, array<string, array{sql: string, bindings: list<mixed>}>, ?Collection<int, FieldDefinition>):QueryShape */
    $this->builderShape = function (array $tree, array $virtual = [], ?Collection $fields = null): QueryShape {
        $query = CustomRecord::query();

        app(RecordFilterCompiler::class)->applyValidatedTree($query, $fields ?? $this->fields, $tree, $virtual);

        return QueryShape::of($query);
    };

    /** @var callable(array<string, mixed>, array<string, array{sql: string, bindings: list<mixed>}>, ?Collection<int, FieldDefinition>):?array{sql: string, bindings: list<mixed>} */
    $this->fragment = fn (array $tree, array $virtual = [], ?Collection $fields = null): ?array => app(RecordFilterCompiler::class)
        ->validatedTreeFragment($fields ?? $this->fields, $tree, $virtual);

    /** @var callable(array<string, mixed>, array<string, array{sql: string, bindings: list<mixed>}>, ?Collection<int, FieldDefinition>):QueryShape */
    $this->fragmentShape = function (array $tree, array $virtual = [], ?Collection $fields = null): QueryShape {
        $fragment = ($this->fragment)($tree, $virtual, $fields);
        $query = CustomRecord::query();

        if ($fragment !== null) {
            $query->whereRaw($fragment['sql'], $fragment['bindings'], 'and');
        }

        return QueryShape::of($query);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('wraps an or group so it cannot widen the tenant scope around it', function (): void {
    $shape = ($this->builderShape)([
        'combinator' => 'or',
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'offen'],
            ['field' => 'stadt', 'operator' => 'equals', 'value' => 'berlin'],
        ],
    ]);

    expect($shape->sql)->toContain("((data->>'status') = ? OR (data->>'stadt') = ?)")
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and($shape->bindings)->toBe(['offen', 'berlin', (string) $this->tenant->getKey()]);
});

it('renders an inclusive range as a bound between clause', function (): void {
    $shape = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'betrag', 'operator' => 'inRange', 'value' => '150', 'valueTo' => '250']],
    ]);

    expect($shape->sql)->toContain('BETWEEN ? AND ?')
        ->and($shape->bindings)->toBe(['150', '250', (string) $this->tenant->getKey()]);
});

it('correlates a relationship predicate on the record id and binds tenant and relationship type', function (): void {
    $has = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'partner', 'operator' => 'has']],
    ]);

    $hasNot = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'partner', 'operator' => 'hasNot']],
    ]);

    expect($has->sql)->toContain('EXISTS (SELECT * FROM record_links WHERE record_links.from_record_id = custom_records.id AND record_links.tenant_id = ? AND record_links.relationship_type_id = ?)')
        ->and($has->sql)->not->toContain('NOT EXISTS')
        ->and($hasNot->sql)->toContain('(NOT EXISTS (SELECT * FROM record_links')
        ->and($has->bindings)->toBe([(string) $this->tenant->getKey(), $this->relationshipTypeId, (string) $this->tenant->getKey()]);
});

it('lets no relationship row through when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    $fragment = ($this->fragment)([
        'combinator' => 'and',
        'conditions' => [['field' => 'partner', 'operator' => 'has']],
    ]);

    expect($fragment)->not->toBeNull()
        ->and((string) $fragment['sql'])->toContain('record_links.tenant_id IS NULL')
        ->and($fragment['bindings'])->toBe([$this->relationshipTypeId]);
});

it('compiles the same sql and bindings whether it builds a fragment or a builder clause', function (array $tree): void {
    $builder = ($this->builderShape)($tree);
    $fragment = ($this->fragmentShape)($tree);

    expect($fragment->sql)->toBe($builder->sql)
        ->and($fragment->bindings)->toBe($builder->bindings);
})->with([
    'deep and' => [[
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'aktiv'],
            [
                'combinator' => 'and',
                'conditions' => [
                    ['field' => 'stufe', 'operator' => 'equals', 'value' => 'a'],
                    ['field' => 'stadt', 'operator' => 'equals', 'value' => 'berlin'],
                ],
            ],
        ],
    ]],
    'mixed nesting' => [[
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'aktiv'],
            [
                'combinator' => 'or',
                'conditions' => [
                    ['field' => 'stufe', 'operator' => 'equals', 'value' => 'b'],
                    ['field' => 'stadt', 'operator' => 'equals', 'value' => 'hamburg'],
                ],
            ],
        ],
    ]],
    'in with values' => [[
        'combinator' => 'and',
        'conditions' => [['field' => 'status', 'operator' => 'in', 'value' => ['aktiv', 'offen']]],
    ]],
    'blank' => [[
        'combinator' => 'and',
        'conditions' => [['field' => 'status', 'operator' => 'blank']],
    ]],
    'relationship' => [[
        'combinator' => 'and',
        'conditions' => [['field' => 'partner', 'operator' => 'hasNot']],
    ]],
    'an empty group beside a condition' => [[
        'combinator' => 'and',
        'conditions' => [
            ['combinator' => 'and', 'conditions' => []],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'aktiv'],
        ],
    ]],
]);

it('blocks every row for an empty in list and keeps every row for an empty not in list', function (): void {
    $blocking = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'status', 'operator' => 'in', 'value' => []]],
    ]);

    $keeping = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'status', 'operator' => 'notIn', 'value' => []]],
    ]);

    expect($blocking->blocksEveryRow())->toBeTrue()
        ->and($keeping->sql)->toContain('(1 = 1)')
        ->and($keeping->blocksEveryRow())->toBeFalse();
});

it('escapes the like wildcards a caller typed instead of letting them widen the match', function (): void {
    $shape = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'stadt', 'operator' => 'contains', 'value' => '100%_x']],
    ]);

    expect($shape->sql)->toContain('ILIKE ?')
        ->and($shape->bindings)->toContain('%100\%\_x%');
});

it('adds no clause at all for an empty tree or an empty group', function (): void {
    $base = QueryShape::of(CustomRecord::query());

    expect(($this->fragment)([]))->toBeNull()
        ->and(($this->fragment)(['combinator' => 'and', 'conditions' => []]))->toBeNull()
        ->and(($this->builderShape)([])->sql)->toBe($base->sql)
        ->and(($this->builderShape)(['combinator' => 'and', 'conditions' => []])->sql)->toBe($base->sql);
});

it('hands out a group as one parenthesised unit that carries its own combinator', function (): void {
    $children = [
        ['field' => 'stufe', 'operator' => 'equals', 'value' => 'b'],
        ['field' => 'stadt', 'operator' => 'equals', 'value' => 'hamburg'],
    ];

    $orFragment = ($this->fragment)(['combinator' => 'or', 'conditions' => $children]);
    $andFragment = ($this->fragment)(['combinator' => 'and', 'conditions' => $children]);

    expect((string) $orFragment['sql'])->toBe("((data->>'stufe') = ? OR (data->>'stadt') = ?)")
        ->and((string) $andFragment['sql'])->toBe("((data->>'stufe') = ? AND (data->>'stadt') = ?)")
        ->and($orFragment['bindings'])->toBe(['b', 'hamburg']);
});

it('lists the bindings in placeholder order and writes no value into the sql', function (): void {
    $fragment = ($this->fragment)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'stadt', 'operator' => 'contains', 'value' => 'ber'],
            [
                'combinator' => 'or',
                'conditions' => [
                    ['field' => 'betrag', 'operator' => 'inRange', 'value' => '150', 'valueTo' => '250'],
                    ['field' => 'status', 'operator' => 'in', 'value' => ['aktiv', 'offen']],
                ],
            ],
            ['field' => 'stufe', 'operator' => 'equals', 'value' => 'c'],
        ],
    ]);

    expect($fragment['bindings'])->toBe(['%ber%', '150', '250', 'aktiv', 'offen', 'c']);

    foreach (['ber', '150', '250', 'aktiv', 'offen'] as $value) {
        expect(str_contains((string) $fragment['sql'], $value))->toBeFalse();
    }
});

it('drops a child it cannot use and keeps the remaining link of the group intact', function (): void {
    $andShape = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'unknown_key', 'operator' => 'equals', 'value' => 'x'],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'aktiv'],
        ],
    ]);

    $orShape = ($this->builderShape)([
        'combinator' => 'or',
        'conditions' => [
            ['field' => 'unknown_key', 'operator' => 'equals', 'value' => 'x'],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'offen'],
        ],
    ]);

    expect($andShape->sql)->toContain("((data->>'status') = ?)")
        ->and($andShape->sql)->not->toContain('unknown_key')
        ->and($andShape->bindings)->toBe(['aktiv', (string) $this->tenant->getKey()])
        ->and($orShape->sql)->toContain("((data->>'status') = ?)")
        ->and($orShape->bindings)->toBe(['offen', (string) $this->tenant->getKey()]);
});

it('uses the supplied expression for a virtual field and otherwise carries no value at all', function (): void {
    /** @var Collection<int, FieldDefinition> $fields */
    $fields = $this->fields->concat(app(SystemFilterFields::class)->agingFields(ModelStub::ulid('compiler-object-type')));

    $tree = [
        'combinator' => 'and',
        'conditions' => [['field' => 'aging_stage', 'operator' => 'greaterThanOrEqual', 'value' => 2]],
    ];

    $virtual = [
        'aging_stage' => [
            'sql' => "(CASE WHEN (data->>'stufe') = ? THEN 3 ELSE 1 END)",
            'bindings' => ['a'],
        ],
    ];

    $withExpression = ($this->builderShape)($tree, $virtual, $fields);
    $withoutExpression = ($this->builderShape)($tree, [], $fields);
    $blank = ($this->builderShape)([
        'combinator' => 'and',
        'conditions' => [['field' => 'aging_stage', 'operator' => 'blank']],
    ], [], $fields);

    expect($withExpression->sql)->toContain("(CASE WHEN (data->>'stufe') = ? THEN 3 ELSE 1 END) >= ?")
        ->and($withExpression->bindings)->toBe(['a', 2, (string) $this->tenant->getKey()])
        ->and($withoutExpression->sql)->toContain('(NULL >= ?)')
        ->and($blank->sql)->toContain('(NULL IS NULL)');
});
