<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Models\FieldDefinition;
use App\Support\Reports\ReportIndexPlanner;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('deals');

    $this->planner = app(ReportIndexPlanner::class);

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type->value,
            'is_translatable' => false,
            'is_encrypted' => false,
            'is_sortable' => false,
            'is_filterable' => false,
            ...$overrides,
        ],
    );

    /** @var callable(FieldType):FieldDefinition */
    $this->systemField = fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('system-'.$key),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => FieldType::SingleSelect->value,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_sortable' => false,
        'is_filterable' => false,
    ]);

    /** @var callable(array<string, mixed>):ReportDefinitionData */
    $this->definition = fn (array $overrides = []): ReportDefinitionData => new ReportDefinitionData(
        $this->objectTypeId,
        $overrides['aggregation'] ?? AggregationType::Count,
        $overrides['aggregationField'] ?? null,
        $overrides['groupByField'] ?? null,
        $overrides['groupByBucket'] ?? null,
        $overrides['seriesField'] ?? null,
        [],
        [],
        [],
        $overrides['groupByLink'] ?? null,
        $overrides['aggregationLink'] ?? null,
    );

    /** @var callable(ReportDefinitionData):list<string> */
    $this->plannedKeys = fn (ReportDefinitionData $definition): array => array_map(
        static fn (FieldDefinition $field): string => $field->key,
        $this->planner->plan($definition),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('plans a grouping on a plain text field that carries neither sort nor filter flag', function (): void {
    expect(($this->plannedKeys)(($this->definition)([
        'groupByField' => ($this->field)('sales_region', FieldType::TextShort),
    ])))->toBe(['sales_region']);
});

it('plans a series field even when the grouping slot itself is excluded', function (): void {
    expect(($this->plannedKeys)(($this->definition)([
        'groupByField' => ($this->field)('closed_on', FieldType::Date),
        'seriesField' => ($this->field)('status', FieldType::TextShort),
    ])))->toBe(['status']);
});

it('plans a distinct count over a text field', function (): void {
    expect(($this->plannedKeys)(($this->definition)([
        'aggregation' => AggregationType::DistinctCount,
        'aggregationField' => ($this->field)('sales_region', FieldType::TextShort),
    ])))->toBe(['sales_region']);
});

it('leaves an aggregation other than a distinct count to the query plan', function (): void {
    foreach ([AggregationType::Sum, AggregationType::Avg, AggregationType::Min, AggregationType::Max] as $aggregation) {
        expect(($this->plannedKeys)(($this->definition)([
            'aggregation' => $aggregation,
            'aggregationField' => ($this->field)('gross_amount', FieldType::Money),
        ])))->toBe([]);
    }
});

it('plans no index for a temporal grouping field, with or without a bucket', function (): void {
    foreach ([FieldType::Date, FieldType::DateTime] as $type) {
        expect(($this->plannedKeys)(($this->definition)([
            'groupByField' => ($this->field)('closed_on', $type),
        ])))->toBe([])
            ->and(($this->plannedKeys)(($this->definition)([
                'groupByField' => ($this->field)('closed_on', $type),
                'groupByBucket' => GroupingBucket::Month,
            ])))->toBe([]);
    }
});

it('excludes an encrypted and a translatable field instead of throwing', function (): void {
    expect(($this->plannedKeys)(($this->definition)([
        'groupByField' => ($this->field)('payroll_total', FieldType::Money, ['is_encrypted' => true]),
    ])))->toBe([])
        ->and(($this->plannedKeys)(($this->definition)([
            'groupByField' => ($this->field)('headline', FieldType::TextShort, ['is_translatable' => true]),
        ])))->toBe([]);
});

it('decides per field whether the report needs its own index', function (string $origin, string $key, FieldType $type, array $overrides, bool $isIndexable): void {
    if ($origin === 'system') {
        $field = ($this->systemField)($key);
        $field->exists = false;
    } elseif ($origin === 'unsaved') {
        $field = new FieldDefinition;
        $field->key = $key;
        $field->object_type_id = $this->objectTypeId;
        $field->field_type = $type;
    } else {
        $field = ($this->field)($key, $type, $overrides);
    }

    expect($this->planner->isIndexable($field))->toBe($isIndexable);
})->with([
    'system field that is a real column' => ['system', 'owner_id', FieldType::SingleSelect, [], false],
    'persisted field that merely carries a system field key' => ['persisted', 'owner_id', FieldType::TextShort, [], true],
    'field that was never persisted' => ['unsaved', 'sales_region', FieldType::TextShort, [], false],
    'sortable field left to the index maintenance' => ['persisted', 'a', FieldType::TextShort, ['is_sortable' => true], false],
    'range filterable field left to the index maintenance' => ['persisted', 'b', FieldType::Money, ['is_filterable' => true], false],
]);

it('plans a field that is only filterable by equality because the maintenance gives it no index of its own', function (): void {
    expect(($this->plannedKeys)(($this->definition)([
        'groupByField' => ($this->field)('industry', FieldType::TextShort, ['is_filterable' => true]),
    ])))->toBe(['industry']);
});

it('plans nothing for a linked slot although the linked field itself would be indexable', function (): void {
    $relation = ($this->field)('customer', FieldType::RelationHasMany);
    $linked = ($this->field)('company_name', FieldType::TextShort);

    $link = new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        ModelStub::ulid('companies'),
        $linked,
    );

    expect(($this->plannedKeys)(($this->definition)([
        'groupByField' => $linked,
        'groupByLink' => $link,
    ])))->toBe([])
        ->and(($this->plannedKeys)(($this->definition)([
            'aggregation' => AggregationType::DistinctCount,
            'aggregationField' => $linked,
            'aggregationLink' => $link,
        ])))->toBe([]);
});

it('plans one index per slot in slot order and one field used twice exactly once', function (): void {
    $region = ($this->field)('sales_region', FieldType::TextShort);
    $status = ($this->field)('status', FieldType::TextShort);
    $owner = ($this->field)('handled_by', FieldType::TextShort);

    expect(($this->plannedKeys)(($this->definition)([
        'aggregation' => AggregationType::DistinctCount,
        'aggregationField' => $owner,
        'groupByField' => $region,
        'seriesField' => $status,
    ])))->toBe(['sales_region', 'status', 'handled_by'])
        ->and(($this->plannedKeys)(($this->definition)([
            'groupByField' => $region,
            'seriesField' => $region,
        ])))->toBe(['sales_region']);
});
