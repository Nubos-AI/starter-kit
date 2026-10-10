<?php

declare(strict_types=1);

use App\Contracts\Modules\ReportQuerySourceInterface;
use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Reports\ReportExpressionCompiler;
use App\Support\Reports\ReportLinkedFieldResolver;
use App\Support\Reports\ReportResultAssembler;
use App\Support\Reports\ReportRunner;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    Config::set('reports.timezone', 'Europe/Berlin');

    $this->objectTypeId = ModelStub::ulid('deals');

    /** @var callable(iterable<ReportQuerySourceInterface>):ReportRunner */
    $this->runnerWith = static fn (iterable $sources = []): ReportRunner => new ReportRunner(
        app(ReportExpressionCompiler::class),
        app(RecordFilterCompiler::class),
        app(ReportResultAssembler::class),
        app(ReportLinkedFieldResolver::class),
        $sources,
    );

    $this->runner = ($this->runnerWith)();

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
            'is_filterable' => true,
            ...$overrides,
        ],
    );

    $this->salesRegion = ($this->field)('sales_region', FieldType::TextShort);
    $this->status = ($this->field)('status', FieldType::TextShort);
    $this->grossAmount = ($this->field)('gross_amount', FieldType::Money);
    $this->closedOn = ($this->field)('closed_on', FieldType::Date);

    /** @var callable(array<string, mixed>):ReportDefinitionData */
    $this->definition = fn (array $overrides = []): ReportDefinitionData => new ReportDefinitionData(
        $this->objectTypeId,
        $overrides['aggregation'] ?? AggregationType::Count,
        $overrides['aggregationField'] ?? null,
        $overrides['groupByField'] ?? null,
        $overrides['groupByBucket'] ?? null,
        $overrides['seriesField'] ?? null,
        $overrides['filterTree'] ?? [],
        $overrides['fields'] ?? [],
        $overrides['systemFieldKeys'] ?? [],
        $overrides['groupByLink'] ?? null,
        $overrides['aggregationLink'] ?? null,
    );

    /** @var callable(ReportDefinitionData):QueryShape */
    $this->shapeOf = fn (ReportDefinitionData $definition): QueryShape => QueryShape::of(
        $this->runner->aggregateQuery($definition),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('narrows the aggregate query to the object type and hides deleted records', function (): void {
    $shape = ($this->shapeOf)(($this->definition)());

    expect($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('counts the rows of a report without a grouping dimension and groups by nothing', function (): void {
    $shape = ($this->shapeOf)(($this->definition)());

    expect($shape->sql)->toContain('COUNT(*) AS aggregate_value')
        ->and($shape->sql)->not->toContain('group by');
});

it('groups, orders and counts one dimension with nulls last', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'groupByField' => $this->salesRegion,
        'fields' => ['sales_region' => $this->salesRegion],
    ]));

    expect($shape->sql)->toContain('AS group_value')
        ->and($shape->sql)->toContain('COUNT(*) AS record_count')
        ->and($shape->sql)->toContain('group by')
        ->and($shape->sql)->toContain('ASC NULLS LAST');
});

it('adds the series expression to the selection, the grouping and the ordering', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'groupByField' => $this->salesRegion,
        'seriesField' => $this->status,
        'fields' => ['sales_region' => $this->salesRegion, 'status' => $this->status],
    ]));

    expect($shape->sql)->toContain('AS series_value')
        ->and(substr_count($shape->sql, 'ASC NULLS LAST'))->toBe(2);
});

it('buckets a temporal grouping field in the configured report zone and not in the session zone', function (): void {
    $berlin = ($this->shapeOf)(($this->definition)([
        'groupByField' => $this->closedOn,
        'groupByBucket' => GroupingBucket::Month,
        'fields' => ['closed_on' => $this->closedOn],
    ]));

    Config::set('reports.timezone', 'America/New_York');

    $newYork = QueryShape::of(($this->runnerWith)()->aggregateQuery(($this->definition)([
        'groupByField' => $this->closedOn,
        'groupByBucket' => GroupingBucket::Month,
        'fields' => ['closed_on' => $this->closedOn],
    ])));

    expect($berlin->sql)->toContain("date_trunc('month'")
        ->and($berlin->sql)->toContain("'Europe/Berlin'")
        ->and($newYork->sql)->toContain("'America/New_York'")
        ->and($newYork->sql)->not->toContain("'Europe/Berlin'");
});

it('renders every bucket level as its own postgres unit', function (): void {
    $units = [];

    foreach (GroupingBucket::cases() as $bucket) {
        $units[$bucket->value] = ($this->shapeOf)(($this->definition)([
            'groupByField' => $this->closedOn,
            'groupByBucket' => $bucket,
            'fields' => ['closed_on' => $this->closedOn],
        ]))->sql;
    }

    foreach ($units as $sql) {
        expect($sql)->toMatch("/date_trunc\('[a-z]+'/");
    }

    expect(count(array_unique($units)))->toBe(count(GroupingBucket::cases()));
});

it('guards a numeric aggregation against unusable json values instead of casting blindly', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'aggregation' => AggregationType::Sum,
        'aggregationField' => $this->grossAmount,
        'fields' => ['gross_amount' => $this->grossAmount],
    ]));

    expect($shape->sql)->toContain("CASE WHEN data->>'gross_amount' ~ '^-?[0-9]+(\\.[0-9]+)?$'")
        ->and($shape->sql)->toContain("THEN (data->>'gross_amount')::numeric END")
        ->and($shape->sql)->toContain('SUM(');
});

it('reports the present but unusable values of a numeric aggregation as a discarded counter', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'aggregation' => AggregationType::Sum,
        'aggregationField' => $this->grossAmount,
        'fields' => ['gross_amount' => $this->grossAmount],
    ]));

    expect($shape->sql)->toContain('AS discarded_count');
});

it('counts no discarded value where the aggregation reads none', function (): void {
    foreach ([AggregationType::Count, AggregationType::DistinctCount] as $aggregation) {
        $shape = ($this->shapeOf)(($this->definition)([
            'aggregation' => $aggregation,
            'aggregationField' => $aggregation === AggregationType::Count ? null : $this->salesRegion,
            'fields' => ['sales_region' => $this->salesRegion],
        ]));

        expect($shape->sql)->not->toContain('AS discarded_count');
    }
});

it('selects the value sum and the usable value count for an average so the collector can divide', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'aggregation' => AggregationType::Avg,
        'aggregationField' => $this->grossAmount,
        'fields' => ['gross_amount' => $this->grossAmount],
    ]));

    expect($shape->sql)->toContain('AS value_sum')
        ->and($shape->sql)->toContain('AS value_count');
});

it('counts distinct values over the grouping expression of the field', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'aggregation' => AggregationType::DistinctCount,
        'aggregationField' => $this->salesRegion,
        'fields' => ['sales_region' => $this->salesRegion],
    ]));

    expect($shape->sql)->toContain('COUNT(DISTINCT');
});

it('groups a system field by its real column rather than a json path', function (): void {
    $owner = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('system-owner'),
        'object_type_id' => $this->objectTypeId,
        'key' => 'owner_id',
        'field_type' => FieldType::SingleSelect->value,
        'is_translatable' => false,
        'is_encrypted' => false,
    ]);

    $owner->exists = false;

    $shape = ($this->shapeOf)(($this->definition)([
        'groupByField' => $owner,
        'fields' => ['owner_id' => $owner],
        'systemFieldKeys' => ['owner_id'],
    ]));

    expect($shape->sql)->toContain('owner_id AS group_value')
        ->and($shape->sql)->not->toContain("data->>'owner_id'");
});

it('narrows the aggregate by the saved filter tree with bound values', function (): void {
    $shape = ($this->shapeOf)(($this->definition)([
        'groupByField' => $this->salesRegion,
        'fields' => ['sales_region' => $this->salesRegion, 'status' => $this->status],
        'filterTree' => [
            'combinator' => 'and',
            'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'won']],
        ],
    ]));

    expect($shape->bindings)->toContain('won')
        ->and($shape->sql)->not->toContain("'won'");
});

it('correlates a linked aggregation over the forward column without a join', function (): void {
    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linked = ($this->field)('revenue', FieldType::Money, ['object_type_id' => ModelStub::ulid('companies')]);

    $link = new ReportLinkedFieldBinding(
        'customer.revenue',
        $relation,
        ModelStub::ulid('rel'),
        ModelStub::ulid('companies'),
        $linked,
    );

    $shape = ($this->shapeOf)(($this->definition)([
        'aggregation' => AggregationType::Sum,
        'aggregationField' => $linked,
        'aggregationLink' => $link,
        'fields' => ['customer' => $relation],
    ]));

    expect($shape->sql)->toContain('SUM(linked_aggregate_value)')
        ->and($shape->sql)->toContain('record_links')
        ->and($shape->sql)->not->toContain(' join ')
        ->and($shape->hasBinding(ModelStub::ulid('rel')))->toBeTrue();
});

it('groups by the linked subquery value when the grouping crosses a relation', function (): void {
    $relation = ($this->field)('customer', FieldType::RelationHasMany, [
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $linked = ($this->field)('company_name', FieldType::TextShort, ['object_type_id' => ModelStub::ulid('companies')]);

    $link = new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        ModelStub::ulid('companies'),
        $linked,
    );

    $shape = ($this->shapeOf)(($this->definition)([
        'groupByField' => $linked,
        'groupByLink' => $link,
        'fields' => ['customer' => $relation],
    ]));

    expect($shape->sql)->toContain('linked_group_value AS group_value')
        ->and($shape->sql)->toContain('MIN(');
});

it('turns an unsupported aggregation into a report refusal that keeps the compiler cause', function (): void {
    try {
        $this->runner->aggregateQuery(($this->definition)([
            'aggregation' => AggregationType::Sum,
            'aggregationField' => $this->salesRegion,
            'fields' => ['sales_region' => $this->salesRegion],
        ]));
    } catch (ReportNotExecutableException $exception) {
        expect($exception->reason)->toBe(ReportNotExecutableReason::UnsupportedAggregation)
            ->and($exception->getPrevious())->not->toBeNull();

        return;
    }

    throw new RuntimeException('The unsupported aggregation was compiled.');
});

it('takes the query of the first module source that claims the definition', function (): void {
    $claimed = DB::query()->from('module_rows');

    $source = new class($claimed) implements ReportQuerySourceInterface
    {
        public function __construct(private readonly QueryBuilder $claimed) {}

        public function query(ReportDefinitionData $definition): ?QueryBuilder
        {
            return $this->claimed;
        }
    };

    $blind = new class implements ReportQuerySourceInterface
    {
        public function query(ReportDefinitionData $definition): ?QueryBuilder
        {
            return null;
        }
    };

    $shape = QueryShape::of(($this->runnerWith)([$blind, $source])->aggregateQuery(($this->definition)()));

    expect($shape->targets('module_rows'))->toBeTrue()
        ->and($shape->targets('custom_records'))->toBeFalse();
});

it('builds the aggregate query without ever sending it', function (): void {
    $reached = QueryShape::attemptedBy(
        fn (): QueryBuilder => $this->runner->aggregateQuery(($this->definition)([
            'groupByField' => $this->salesRegion,
            'fields' => ['sales_region' => $this->salesRegion],
        ])),
    );

    expect($reached)->toBeNull();
});
