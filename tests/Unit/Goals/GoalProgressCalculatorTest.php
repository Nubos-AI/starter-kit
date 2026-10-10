<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Enums\Reports\AggregationType;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\Goal;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Goals\GoalPeriodCalculator;
use App\Support\Goals\GoalProgressCalculator;
use App\Support\Goals\GoalScopeCompiler;
use App\Support\Goals\GoalTargetTeamResolver;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportRunner;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use Tests\Unit\Goals\Doubles\StaticGoalTargetTeamResolver;
use Tests\Unit\Goals\Doubles\StaticMaintenanceLockRegistry;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['reports.timezone' => 'Europe/Berlin', 'reports.k_anonymity_threshold' => 5]);

    $this->tenant = AccessContext::tenant();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'filter_definition' => ['combinator' => 'and', 'conditions' => [['field' => 'stage']]],
        'aggregation_type' => AggregationType::Count,
        'aggregation_field_key' => null,
        'group_by_field_key' => null,
        'group_by_bucket' => null,
        'series_field_key' => null,
    ], ['objectType' => $this->objectType]);

    $this->goal = ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('goal'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('owner'),
        'report_id' => (string) $this->report->getKey(),
        'scope_type' => GoalScopeType::User,
        'scope_field_key' => 'owner_id',
        'period_field_key' => null,
        'period_type' => GoalPeriodType::Month,
        'target_user_id' => ModelStub::ulid('target'),
        'includes_subteams' => false,
    ]);

    $this->actingUser = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('acting'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->bounds = app(GoalPeriodCalculator::class)->boundsFor(
        GoalPeriodType::Month,
        new CarbonImmutable('2026-05-15T09:00:00Z'),
    );

    $this->seenDefinitions = [];
    $this->seenViewers = [];

    $this->validator = Mockery::mock(ReportDefinitionValidator::class);
    $this->validator->shouldReceive('validate')->andReturnUsing(
        function (array $definition, ObjectType $objectType, User $viewer): ReportDefinitionData {
            $this->seenDefinitions[] = $definition;
            $this->seenViewers[] = (string) $viewer->getKey();

            /** @var array<string, mixed> $filterTree */
            $filterTree = $definition['filter_definition'] ?? [];

            return new ReportDefinitionData(
                objectTypeId: (string) $objectType->getKey(),
                aggregation: AggregationType::Count,
                aggregationField: null,
                groupByField: null,
                groupByBucket: null,
                seriesField: null,
                filterTree: $filterTree,
                fields: [],
                systemFieldKeys: [],
            );
        },
    );

    /** @var callable(?string, int):ReportRunner */
    $this->runner = static function (?string $total, int $recordCount): ReportRunner {
        $runner = Mockery::mock(ReportRunner::class);
        $runner->shouldReceive('run')->andReturn(new ReportResultData(
            aggregation: AggregationType::Count,
            rows: [],
            total: $total,
            recordCount: $recordCount,
            discardedValueCount: 0,
            isSuppressed: false,
            generatedAt: '2026-05-15T09:00:00+00:00',
        ));

        return $runner;
    };

    /** @var callable(?string, int):GoalProgressCalculator */
    $this->calculator = fn (?string $total = '7', int $recordCount = 9): GoalProgressCalculator => new GoalProgressCalculator(
        app(GoalPeriodCalculator::class),
        new GoalScopeCompiler(app(FieldTypeRegistry::class), new StaticGoalTargetTeamResolver),
        $this->validator,
        ($this->runner)($total, $recordCount),
        new StaticMaintenanceLockRegistry,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(GoalTargetTeamResolver::class);
});

it('restricts the report definition to the goal target before it runs anything', function (): void {
    ($this->calculator)()->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, false);

    $conditions = $this->seenDefinitions[0]['filter_definition']['conditions'];

    expect($conditions)->toHaveCount(2)
        ->and($conditions[0])->toBe(['field' => 'stage'])
        ->and($conditions[1])->toBe([
            'field' => 'owner_id',
            'operator' => 'in',
            'value' => [ModelStub::ulid('target')],
        ]);
});

it('hands the validator only the definition keys of the report and nothing else of the row', function (): void {
    ($this->calculator)()->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, false);

    expect(array_keys($this->seenDefinitions[0]))->toBe([
        'filter_definition',
        'aggregation_type',
        'aggregation_field_key',
        'group_by_field_key',
        'group_by_bucket',
        'series_field_key',
    ]);
});

it('validates in the context of the acting user it was handed', function (): void {
    ($this->calculator)()->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, false);

    expect($this->seenViewers)->toBe([(string) $this->actingUser->getKey()]);
});

it('returns the total the runner produced', function (): void {
    expect(($this->calculator)('1234.5600', 9)->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, false))
        ->toBe('1234.5600');
});

it('withholds an anonymised value that rests on fewer records than the anonymity threshold', function (): void {
    expect(($this->calculator)('3', 4)->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, true))
        ->toBeNull()
        ->and(($this->calculator)('3', 5)->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, true))
        ->toBe('3');
});

it('keeps an unanonymised value even below the anonymity threshold', function (): void {
    expect(($this->calculator)('3', 1)->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, false))
        ->toBe('3');
});

it('reads the anonymity threshold from the configuration and carries no literal', function (): void {
    config(['reports.k_anonymity_threshold' => 20]);

    expect(($this->calculator)('3', 19)->progressFor($this->goal, $this->report, $this->actingUser, $this->bounds, true))
        ->toBeNull();
});

it('refuses a report whose object type is gone instead of counting everything', function (): void {
    $orphan = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('orphan-report'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('vanished'),
    ], ['objectType' => null]);

    expect(fn (): ?string => ($this->calculator)()->progressFor($this->goal, $orphan, $this->actingUser, $this->bounds, false))
        ->toThrow(ReportNotExecutableException::class);
});

it('looks the source report up inside the tenant of the goal and never by id alone', function (): void {
    $shape = QueryShape::attemptedBy(fn (): Report => ($this->calculator)()->sourceReport($this->goal));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('reports', (string) $this->report->getKey()))->toBeTrue();
});

it('scans the goals of every tenant when it looks for due work', function (): void {
    $shape = QueryShape::attemptedBy(fn (): array => ($this->calculator)()->dueGoalIds(
        new CarbonImmutable('2026-05-15T09:00:00Z'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('goals'))->toBeTrue()
        ->and($shape->hasColumnCondition('goals', 'tenant_id'))->toBeFalse()
        ->and($shape->hidesSoftDeleted('goals'))->toBeTrue();
});

it('looks a goal up across every tenant because the scan runs outside a request', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?Goal => ($this->calculator)()->goalFor((string) $this->goal->getKey()));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('goals'))->toBeTrue()
        ->and($shape->isKeyedTo('goals', (string) $this->goal->getKey()))->toBeTrue()
        ->and($shape->hasColumnCondition('goals', 'tenant_id'))->toBeFalse();
});
