<?php

declare(strict_types=1);

use App\Activities\Goals\ComputeGoalProgressActivity;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Enums\Reports\ReportExecutionMode;
use App\Models\Goal;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalPeriodCalculator;
use App\Support\Goals\GoalThresholdEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use Tests\Unit\Goals\Doubles\RecordingGoalProgressCalculator;
use Tests\Unit\Goals\Doubles\RecordingRecordGoalProgressAction;
use Tests\Unit\Goals\Doubles\RecordingReportExecutionContext;
use Tests\Unit\Goals\Doubles\StaticMaintenanceLockRegistry;
use Tests\Unit\Goals\Doubles\StaticReportExecutionModeResolver;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);

    CarbonImmutable::setTestNow(new CarbonImmutable('2026-05-15T09:00:00Z'));

    $this->tenantId = ModelStub::ulid('goal-tenant');
    $this->ownerId = ModelStub::ulid('goal-owner');
    $this->definerId = ModelStub::ulid('report-definer');

    $this->owner = ModelStub::make(User::class, ['id' => $this->ownerId, 'tenant_id' => $this->tenantId]);
    $this->definer = ModelStub::make(User::class, ['id' => $this->definerId, 'tenant_id' => $this->tenantId]);

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('goal-report'),
        'tenant_id' => $this->tenantId,
        'owner_id' => $this->definerId,
        'object_type_id' => ModelStub::ulid('companies'),
    ]);

    $this->goal = ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('the-goal'),
        'tenant_id' => $this->tenantId,
        'owner_id' => $this->ownerId,
        'report_id' => (string) $this->report->getKey(),
        'scope_type' => GoalScopeType::User,
        'period_type' => GoalPeriodType::Month,
        'target_user_id' => $this->ownerId,
        'scope_field_key' => 'owner_id',
    ]);

    $this->calculator = (new RecordingGoalProgressCalculator)
        ->withGoal($this->goal)
        ->withReport($this->report);

    $this->context = (new RecordingReportExecutionContext)
        ->withUser($this->owner)
        ->withUser($this->definer);

    $this->action = new RecordingRecordGoalProgressAction;
    $this->locks = new StaticMaintenanceLockRegistry;

    /** @var callable(ReportExecutionMode):ComputeGoalProgressActivity */
    $this->activity = fn (ReportExecutionMode $mode = ReportExecutionMode::Viewer): ComputeGoalProgressActivity => new ComputeGoalProgressActivity(
        app(GoalPeriodCalculator::class),
        $this->calculator,
        $this->context,
        $this->action,
        new GoalThresholdEvaluator,
        $this->locks,
        new StaticReportExecutionModeResolver($mode),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    AccessContext::forgetTenant();
});

it('computes the progress in the context of the goal owner and inside the tenant of the goal', function (): void {
    GateSpy::allowing('view');

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeTrue()
        ->and($this->context->calls)->toBe([[
            'role' => 'viewer',
            'tenantId' => $this->tenantId,
            'userId' => $this->ownerId,
        ]])
        ->and($this->calculator->calls[0]['actingUserId'])->toBe($this->ownerId);
});

it('ignores the signed in caller and stays with the owner of the goal', function (): void {
    GateSpy::allowing('view');

    $tenant = AccessContext::tenant('goal-tenant');
    $caller = AccessContext::user($tenant, [], 'unrelated-caller');

    AccessContext::actAs($caller);
    $this->context->withUser($caller);

    ($this->activity)()->computeGoalProgress((string) $this->goal->getKey());

    expect($this->context->calls[0]['userId'])->toBe($this->ownerId)
        ->and($this->context->calls[0]['userId'])->not->toBe((string) $caller->getKey())
        ->and($this->calculator->calls[0]['actingUserId'])->toBe($this->ownerId);
});

it('asks the gate whether the goal owner may view the source report', function (): void {
    $spy = GateSpy::allowing('view');

    ($this->activity)()->computeGoalProgress((string) $this->goal->getKey());

    expect($spy->wasAskedFor('view'))->toBeTrue()
        ->and($spy->calls[0]['arguments'][0])->toBe($this->report);
});

it('refuses to record anything when the owner may not view the source report', function (): void {
    GateSpy::allowing();
    Log::spy();

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeFalse()
        ->and($this->calculator->calls)->toBe([])
        ->and($this->action->calls)->toBe([]);

    Log::shouldHaveReceived('error')->once()->withArgs(
        static fn (string $message, array $context): bool => $context['reason'] === 'source_not_visible',
    );
});

it('runs a viewer mode report unanonymised and never escalates to the definer', function (): void {
    GateSpy::allowing('view');

    ($this->activity)(ReportExecutionMode::Viewer)->computeGoalProgress((string) $this->goal->getKey());

    expect($this->calculator->calls[0]['isAnonymised'])->toBeFalse()
        ->and(array_column($this->context->calls, 'role'))->toBe(['viewer']);
});

it('escalates a definer mode report to the report owner and anonymises the result', function (): void {
    GateSpy::allowing('view');

    ($this->activity)(ReportExecutionMode::Definer)->computeGoalProgress((string) $this->goal->getKey());

    expect($this->context->calls)->toBe([
        ['role' => 'viewer', 'tenantId' => $this->tenantId, 'userId' => $this->ownerId],
        ['role' => 'definer', 'tenantId' => $this->tenantId, 'userId' => $this->definerId],
    ])
        ->and($this->calculator->calls[0]['actingUserId'])->toBe($this->definerId)
        ->and($this->calculator->calls[0]['isAnonymised'])->toBeTrue();
});

it('checks the owner may view the report before it escalates to the definer', function (): void {
    GateSpy::allowing();
    Log::spy();

    ($this->activity)(ReportExecutionMode::Definer)->computeGoalProgress((string) $this->goal->getKey());

    expect(array_column($this->context->calls, 'role'))->toBe(['viewer'])
        ->and($this->calculator->calls)->toBe([]);
});

it('records no period at all when the value is withheld', function (): void {
    GateSpy::allowing('view');
    Log::spy();

    $this->calculator->withValue(null);

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeFalse()
        ->and($this->action->calls)->toBe([]);

    Log::shouldHaveReceived('error')->once()->withArgs(
        static fn (string $message, array $context): bool => $context['reason'] === 'value-not-available',
    );
});

it('refuses a goal that no longer exists instead of computing a foreign one', function (): void {
    GateSpy::allowing('view');
    Log::spy();

    $this->calculator->withGoal(null);

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeFalse()
        ->and($this->context->calls)->toBe([])
        ->and($this->action->calls)->toBe([]);

    Log::shouldHaveReceived('error')->once()->withArgs(
        static fn (string $message, array $context): bool => $context['reason'] === 'source_not_visible',
    );
});

it('records nothing while the tenant of the goal is under maintenance', function (): void {
    GateSpy::allowing('view');
    Log::spy();

    $this->locks = new StaticMaintenanceLockRegistry([$this->tenantId]);

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeFalse()
        ->and($this->context->calls)->toBe([])
        ->and($this->action->calls)->toBe([]);

    Log::shouldHaveReceived('error')->once();
});

it('keeps recording while another tenant is under maintenance', function (): void {
    GateSpy::allowing('view');

    $this->locks = new StaticMaintenanceLockRegistry([ModelStub::ulid('other-tenant')]);

    expect(($this->activity)()->computeGoalProgress((string) $this->goal->getKey()))->toBeTrue()
        ->and($this->action->calls)->toHaveCount(1);
});

it('hands the action the value and the period bounds of the goal period type', function (): void {
    GateSpy::allowing('view');

    ($this->activity)()->computeGoalProgress((string) $this->goal->getKey());

    expect($this->action->calls)->toBe([[
        'goalId' => (string) $this->goal->getKey(),
        'value' => '42',
        'start' => '2026-04-30T22:00:00+00:00',
        'end' => '2026-05-31T22:00:00+00:00',
        'thresholds' => [],
    ]])
        ->and($this->calculator->calls[0]['start'])->toBe('2026-04-30T22:00:00+00:00')
        ->and($this->calculator->calls[0]['end'])->toBe('2026-05-31T22:00:00+00:00');
});

it('asks the calculator for the goal it was handed and for no other', function (): void {
    GateSpy::allowing('view');

    ($this->activity)()->computeGoalProgress((string) $this->goal->getKey());

    expect($this->calculator->goalLookups)->toBe([(string) $this->goal->getKey()]);
});
