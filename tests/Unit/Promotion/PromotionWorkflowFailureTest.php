<?php

declare(strict_types=1);

use App\Actions\Promotion\FailAbandonedPromotionRunAction;
use App\Activities\Promotion\RunPromotionActivity;
use App\DTOs\Promotion\PromotionRunData;
use App\Enums\Promotion\PromotionRunStatus;
use App\Models\PromotionRun;
use App\Support\Audit\AdminArtifactAuditor;
use App\Workflows\Promotion\PromotionWorkflow;
use Temporal\DataConverter\EncodedValues;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\TimeoutFailure;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('promotion-failure-tenant');
    $this->runId = ModelStub::ulid('promotion-failure-run');

    $this->input = new PromotionRunData(
        tenantId: $this->tenantId,
        promotionRunId: $this->runId,
        actingUserId: ModelStub::ulid('promotion-failure-actor'),
    );

    $this->crash = new ActivityFailure(5, 6, 'Promotion.runPromotion', 'activity-1', 0, 'worker', new TimeoutFailure('activity timed out', EncodedValues::empty(), 0));
});

afterEach(function (): void {
    WorkflowRun::end();
    StaticQueryConnection::uninstall();
    app()->forgetInstance(AdminArtifactAuditor::class);
    Mockery::close();
});

it('marks a promotion run failed through an activity when the promotion activity dies and still fails the workflow', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PromotionWorkflow)->run($this->input))
        ->fail($this->crash);

    $finalize = collect($run->activities())->firstWhere('activity', 'Promotion.failAbandonedPromotion');

    expect($finalize['arguments'] ?? null)->toBe([$this->tenantId, $this->runId])
        ->and(fn () => $run->resume(true))->toThrow(ActivityFailure::class);

    $error = collect($run->logs)->firstWhere('level', 'error');

    expect($error['context'] ?? [])->toMatchArray([
        'workflow_id' => $run->workflowId,
        'run_id' => $run->runId,
        'tenant_id' => $this->tenantId,
        'promotion_run_id' => $this->runId,
        'run_marked_failed' => true,
    ]);
});

it('runs no failure finalization when the promotion activity completes', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PromotionWorkflow)->run($this->input))
        ->resume(PromotionRunStatus::Completed->value);

    expect($run->isFinished())->toBeTrue()
        ->and(collect($run->activities())->pluck('activity')->all())->toBe(['Promotion.runPromotion']);
});

it('moves only a run still applying to failed and audits it', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "promotion_runs"')
            ? [['id' => $this->runId, 'tenant_id' => $this->tenantId, 'status' => PromotionRunStatus::Failed->value]]
            : [],
        static fn (string $sql): int => str_starts_with($sql, 'update "promotion_runs" set "status"') ? 1 : 0,
    );

    $auditor = Mockery::mock(AdminArtifactAuditor::class);
    $auditor->shouldReceive('recordEvent')->once()->with(Mockery::type(PromotionRun::class), 'operation.promotion_failed', Mockery::type('array'), $this->tenantId);
    app()->instance(AdminArtifactAuditor::class, $auditor);

    $marked = app(FailAbandonedPromotionRunAction::class)->execute($this->tenantId, $this->runId);

    $claim = $connection->writtenStatements[0];

    expect($marked)->toBeTrue()
        ->and($claim['sql'])->toContain('"status" = ?')
        ->and($claim['bindings'])->toContain(PromotionRunStatus::Failed->value)
        ->and($claim['bindings'])->toContain(PromotionRunStatus::Applying->value)
        ->and($claim['bindings'])->toContain($this->tenantId)
        ->and($claim['bindings'])->toContain($this->runId);
});

it('leaves a run that already left applying untouched and records no audit', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 0);

    $auditor = Mockery::mock(AdminArtifactAuditor::class);
    $auditor->shouldNotReceive('recordEvent');
    app()->instance(AdminArtifactAuditor::class, $auditor);

    expect(app(FailAbandonedPromotionRunAction::class)->execute($this->tenantId, $this->runId))->toBeFalse()
        ->and($connection->writtenStatements)->toHaveCount(1);
});

it('delegates the failure finalization activity to the action', function (): void {
    $action = Mockery::mock(FailAbandonedPromotionRunAction::class);
    $action->shouldReceive('execute')->once()->with($this->tenantId, $this->runId)->andReturn(true);
    app()->instance(FailAbandonedPromotionRunAction::class, $action);

    expect(app(RunPromotionActivity::class)->failAbandonedPromotion($this->tenantId, $this->runId))->toBeTrue();

    app()->forgetInstance(FailAbandonedPromotionRunAction::class);
});
