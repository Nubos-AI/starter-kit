<?php

declare(strict_types=1);

use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\Enums\Approvals\ApprovalEventType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\PromotionRun;
use App\Support\Approvals\PromotionRunOutcomeHandler;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->foreignTenantId = ModelStub::ulid('process-tenant');
    $this->runId = ModelStub::ulid('promotion-run');

    $this->dispatcher = Mockery::mock(PromotionDispatcherInterface::class);
    $this->auditor = Mockery::mock(AdminArtifactAuditor::class);

    $this->handler = new PromotionRunOutcomeHandler($this->dispatcher, $this->auditor);

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('process'),
        'tenant_id' => $this->foreignTenantId,
        'record_id' => null,
        'anchor_type' => PromotionRun::class,
        'anchor_id' => $this->runId,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('audits a decision against the run of the process tenant even when a foreign tenant is bound', function (): void {
    $this->auditor->shouldNotReceive('recordEvent');

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->handler->recordDecision(
        $this->process,
        ModelStub::make(ApprovalProcessStage::class, ['position' => 1]),
        ModelStub::make(ApprovalEvent::class, ['type' => ApprovalEventType::Approved->value]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('promotion_runs'))->toBeTrue()
        ->and($shape->isKeyedTo('promotion_runs', $this->runId))->toBeTrue()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse()
        ->and($shape->hidesSoftDeleted('promotion_runs'))->toBeFalse();
});

it('locks the run of the process tenant before it marks the decided promotion', function (Closure $markOutcome): void {
    $this->dispatcher->shouldNotReceive('start');

    $shape = QueryShape::attemptedBy(fn (): mixed => $markOutcome($this->handler, $this->process));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('promotion_runs'))->toBeTrue()
        ->and($shape->isKeyedTo('promotion_runs', $this->runId))->toBeTrue()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse()
        ->and($shape->hidesSoftDeleted('promotion_runs'))->toBeTrue()
        ->and($shape->sql)->toContain('for update');
})->with([
    'approved' => [static fn (PromotionRunOutcomeHandler $handler, ApprovalProcess $process): mixed => $handler->applyApproved($process)],
    'rejected' => [static fn (PromotionRunOutcomeHandler $handler, ApprovalProcess $process): mixed => $handler->applyRejected($process, 'zu riskant')],
]);

it('hands an approved run to its workflow only once the approval has committed', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "promotion_runs"')
            ? [['id' => $this->runId, 'tenant_id' => $this->foreignTenantId, 'status' => 'awaiting_approval']]
            : [],
        static fn (): int => 1,
    );

    $started = [];
    $this->dispatcher->allows('start')->andReturnUsing(function (PromotionRun $run) use (&$started): void {
        $started[] = (string) $run->getKey();
    });

    $this->handler->applyApproved($this->process);

    $startedBeforeCommit = $started;

    foreach ($connection->deferredAfterCommit as $callback) {
        $callback();
    }

    expect($startedBeforeCommit)->toBe([])
        ->and($connection->deferredAfterCommit)->toHaveCount(1)
        ->and($connection->writtenSqlOf('promotion_runs'))->toHaveCount(1)
        ->and($started)->toBe([$this->runId]);
});

it('logs a failed hand over of an approved run with its identifiers and throws it on', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "promotion_runs"')
            ? [['id' => $this->runId, 'tenant_id' => $this->foreignTenantId, 'status' => 'awaiting_approval']]
            : [],
        static fn (): int => 1,
    );

    $failure = new RuntimeException('temporal unreachable');
    $this->dispatcher->expects('start')->andThrow($failure);

    Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Handing an approved promotion run over to its workflow failed.'
        && $context['tenant_id'] === $this->foreignTenantId
        && $context['promotion_run_id'] === $this->runId
        && $context['exception_class'] === RuntimeException::class);

    $this->handler->applyApproved($this->process);

    expect(fn (): mixed => ($connection->deferredAfterCommit[0])())->toThrow($failure);
});
