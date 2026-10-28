<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportDrillDownData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExpressionCompiler;
use App\Support\Reports\ReportGridScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'key' => $key,
        'field_type' => $type->value,
        'is_translatable' => false,
        'is_encrypted' => false,
        'is_filterable' => true,
        'is_sortable' => false,
    ]);

    $this->salesRegion = ($this->field)('sales_region', FieldType::TextShort);
    $this->status = ($this->field)('status', FieldType::TextShort);
    $this->closedOn = ($this->field)('closed_on', FieldType::Date);

    /** @var callable(array<string, mixed>):ReportDefinitionData */
    $this->definitionOf = fn (array $overrides = []): ReportDefinitionData => new ReportDefinitionData(
        (string) $this->objectType->getKey(),
        $overrides['aggregation'] ?? AggregationType::Count,
        null,
        array_key_exists('groupByField', $overrides) ? $overrides['groupByField'] : $this->salesRegion,
        $overrides['groupByBucket'] ?? null,
        $overrides['seriesField'] ?? null,
        $overrides['filterTree'] ?? [],
        $overrides['fields'] ?? ['sales_region' => $this->salesRegion],
        [],
        $overrides['groupByLink'] ?? null,
    );

    /** @var callable(ReportDefinitionData|ReportNotExecutableException):ReportGridScope */
    $this->scopeFor = function (ReportDefinitionData|ReportNotExecutableException $outcome): ReportGridScope {
        $validator = Mockery::mock(ReportDefinitionValidator::class);

        $expectation = $validator->shouldReceive('validate');

        $outcome instanceof ReportDefinitionData
            ? $expectation->andReturn($outcome)
            : $expectation->andThrow($outcome);

        return new ReportGridScope(
            $validator,
            app(ReportExpressionCompiler::class),
            app(RecordFilterCompiler::class),
        );
    };

    /** @var callable(string, ?string):ReportDrillDownData */
    $this->drillDown = static fn (string $groupToken, ?string $seriesToken = null): ReportDrillDownData => new ReportDrillDownData(
        ModelStub::ulid('report'),
        null,
        null,
        $groupToken,
        $seriesToken,
    );

    /** @var callable():Builder<CustomRecord> */
    $this->query = static fn (): Builder => CustomRecord::query();

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));

    /** @var callable(ReportGridScope, ReportDrillDownData):string */
    $this->refusalOf = function (ReportGridScope $scope, ReportDrillDownData $drillDown): string {
        try {
            $scope->apply(($this->query)(), [], $drillDown, $this->objectType, ($this->viewer)());
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['report'] ?? [];

            return (string) ($messages[0] ?? '');
        }

        throw new RuntimeException('The drill-down was accepted although a refusal was expected.');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('narrows the list to exactly the clicked group', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)());
    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)('v:north'), $this->objectType, ($this->viewer)());

    $shape = QueryShape::of($query);

    expect($shape->sql)->toContain('IS NOT DISTINCT FROM ?')
        ->and($shape->bindings)->toContain('north');
});

it('matches a missing value with the null token and never with the empty string', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)());
    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)('null'), $this->objectType, ($this->viewer)());

    expect(QueryShape::of($query)->bindings)->toContain(null);

    $empty = ($this->query)();

    ($this->scopeFor)(($this->definitionOf)())
        ->apply($empty, [], ($this->drillDown)('v:'), $this->objectType, ($this->viewer)());

    expect(QueryShape::of($empty)->bindings)->toContain('')
        ->and(QueryShape::of($empty)->bindings)->not->toContain(null);
});

it('binds a group value carrying sql metacharacters instead of inlining it', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)());
    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)("v:north'; drop table custom_records; --"), $this->objectType, ($this->viewer)());

    $shape = QueryShape::of($query);

    expect($shape->bindings)->toContain("north'; drop table custom_records; --")
        ->and($shape->sql)->not->toContain('drop table');
});

it('narrows both axes of a two dimensional report', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)([
        'seriesField' => $this->status,
        'fields' => ['sales_region' => $this->salesRegion, 'status' => $this->status],
    ]));

    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)('v:north', 'v:won'), $this->objectType, ($this->viewer)());

    $shape = QueryShape::of($query);

    expect(substr_count($shape->sql, 'IS NOT DISTINCT FROM ?'))->toBe(2)
        ->and($shape->bindings)->toContain('north')
        ->and($shape->bindings)->toContain('won');
});

it('refuses the collector token on either axis instead of filtering by it', function (): void {
    $onGroup = ($this->refusalOf)(($this->scopeFor)(($this->definitionOf)()), ($this->drillDown)('other'));

    $onSeries = ($this->refusalOf)(
        ($this->scopeFor)(($this->definitionOf)([
            'seriesField' => $this->status,
            'fields' => ['sales_region' => $this->salesRegion, 'status' => $this->status],
        ])),
        ($this->drillDown)('v:north', 'other'),
    );

    expect($onGroup)->not->toBe('')
        ->and($onSeries)->toBe($onGroup);
});

it('refuses a group token that carries neither the value prefix nor the null marker', function (): void {
    expect(($this->refusalOf)(($this->scopeFor)(($this->definitionOf)()), ($this->drillDown)('north')))
        ->not->toBe('');
});

it('refuses a report without a grouping dimension', function (): void {
    expect(($this->refusalOf)(
        ($this->scopeFor)(($this->definitionOf)(['groupByField' => null, 'fields' => []])),
        ($this->drillDown)('v:north'),
    ))->not->toBe('');
});

it('refuses a report grouped by a linked field with a message instead of a database error', function (): void {
    $relation = ($this->field)('customer', FieldType::RelationHasMany);

    $link = new ReportLinkedFieldBinding(
        'customer.company_name',
        $relation,
        ModelStub::ulid('rel'),
        ModelStub::ulid('companies'),
        ($this->field)('company_name', FieldType::TextShort),
    );

    expect(($this->refusalOf)(
        ($this->scopeFor)(($this->definitionOf)(['groupByLink' => $link])),
        ($this->drillDown)('v:north'),
    ))->not->toBe('');
});

it('refuses a series token for a report that carries no series dimension', function (): void {
    expect(($this->refusalOf)(($this->scopeFor)(($this->definitionOf)()), ($this->drillDown)('v:north', 'v:won')))
        ->not->toBe('');
});

it('refuses a missing series token for a report that carries a series dimension', function (): void {
    expect(($this->refusalOf)(
        ($this->scopeFor)(($this->definitionOf)([
            'seriesField' => $this->status,
            'fields' => ['sales_region' => $this->salesRegion, 'status' => $this->status],
        ])),
        ($this->drillDown)('v:north'),
    ))->not->toBe('');
});

it('turns a refused definition into a field bound refusal rather than letting it escape', function (): void {
    $scope = ($this->scopeFor)(new ReportNotExecutableException(ReportNotExecutableReason::FieldNotReadable));

    expect(($this->refusalOf)($scope, ($this->drillDown)('v:north')))->not->toBe('');
});

it('applies the saved filter tree of the report next to the clicked cell', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)([
        'filterTree' => [
            'combinator' => 'and',
            'conditions' => [['field' => 'sales_region', 'operator' => 'equals', 'value' => 'north']],
        ],
    ]));

    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)('v:north'), $this->objectType, ($this->viewer)());

    $shape = QueryShape::of($query);

    expect(substr_count($shape->sql, 'north'))->toBe(0)
        ->and(array_count_values(array_map(strval(...), $shape->bindings))['north'] ?? 0)->toBe(2);
});

it('resolves the group expression of a bucketed report through the bucket instead of the raw value', function (): void {
    $scope = ($this->scopeFor)(($this->definitionOf)([
        'groupByField' => $this->closedOn,
        'groupByBucket' => GroupingBucket::Month,
        'fields' => ['closed_on' => $this->closedOn],
    ]));

    $query = ($this->query)();

    $scope->apply($query, [], ($this->drillDown)('v:2026-01-01T00:00:00+01:00'), $this->objectType, ($this->viewer)());

    expect(QueryShape::of($query)->sql)->toContain("date_trunc('month'");
});
