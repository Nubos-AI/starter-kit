<?php

declare(strict_types=1);

use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalEscalationResultData;
use App\DTOs\Approvals\ApprovalProcessData;
use App\DTOs\Approvals\ApprovalProcessStateData;
use App\DTOs\Approvals\ApprovalStepResultData;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStepOutcome;
use App\Workflows\Approvals\ApprovalProcessWorkflow;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->input = new ApprovalProcessData('tenant-1', 'process-1');
    $this->decision = new ApprovalCommandData('tenant-1', 'process-1', 'approver-1', ['decision' => 'approve']);
    $this->cancellation = new ApprovalCommandData('tenant-1', 'process-1', 'requester-1', ['reason' => 'obsolete']);

    $this->pending = fn (bool $isEscalationApplied = false): ApprovalProcessStateData => new ApprovalProcessStateData(ApprovalProcessStatus::Pending, 'stage-1', '2026-01-05T10:00:00+00:00', $isEscalationApplied, null);
    $this->ended = fn (ApprovalProcessStatus $status): ApprovalProcessStateData => new ApprovalProcessStateData($status, null, null, false, 'event-1');

    /** @var Closure(): array{WorkflowRun, ApprovalProcessWorkflow} */
    $this->openProcess = function (): array {
        $workflow = new ApprovalProcessWorkflow;
        $run = WorkflowRun::start(fn (): Generator => $workflow->run($this->input))
            ->resume(($this->pending)())
            ->resume();

        expect($run->waitsFor())->toBe('awaitWithTimeout');

        return [$run, $workflow];
    };
});

afterEach(function (): void {
    WorkflowRun::end();
});

it('applies one change at a time and never escalates while a decision and a cancellation run as the deadline expires and the stage changes', function (): void {
    [$run, $workflow] = ($this->openProcess)();

    $run->handle('decide', $workflow->decide($this->decision))
        ->handle('cancel', $workflow->cancel($this->cancellation));

    expect($run->waitsFor('decide'))->toBe('approvals.decide')
        ->and($run->waitsFor('cancel'))->toBe('await');

    $run->fireTimer()
        ->signal(fn () => $workflow->stageChanged());

    expect($run->activitiesNamed('approvals.escalateStage'))->toBe([]);

    $run->resume(new ApprovalStepResultData(ApprovalStepOutcome::Approved, 'event-1'), 'decide');

    expect($run->resultOf('decide'))->toBe('approved')
        ->and($run->waitsFor('cancel'))->toBe('approvals.cancel');

    $run->fail(new RuntimeException('The approval process is already decided.'), 'cancel');

    expect($run->waitsFor())->toBe('approvals.describeProcess');

    $run->resume(($this->ended)(ApprovalProcessStatus::Approved))->resume();

    expect($run->activitiesNamed('approvals.decide'))->toHaveCount(1)
        ->and($run->activitiesNamed('approvals.cancel'))->toHaveCount(1)
        ->and($run->failureOf('cancel'))->toBeInstanceOf(RuntimeException::class)
        ->and($run->activitiesNamed('approvals.escalateStage'))->toBe([])
        ->and($run->activitiesNamed('approvals.notifyDecision'))->toBe([['handler' => 'run', 'arguments' => ['tenant-1', 'event-1']]])
        ->and($run->isFinished())->toBeTrue();
});

it('lets a decision that arrives during an escalation wait for it and escalates nothing once the process is decided or cancelled', function (): void {
    [$run, $workflow] = ($this->openProcess)();

    $run->fireTimer();

    expect($run->waitsFor())->toBe('approvals.escalateStage');

    $run->handle('decide', $workflow->decide($this->decision));

    expect($run->waitsFor('decide'))->toBe('await');

    $run->resume(new ApprovalEscalationResultData(ApprovalEscalationType::Delegate));

    expect($run->waitsFor('decide'))->toBe('approvals.decide')
        ->and($run->waitsFor())->toBe('approvals.notifyEscalation');

    $run->resume(new ApprovalStepResultData(ApprovalStepOutcome::Approved, 'event-1'), 'decide')
        ->resume()
        ->resume(($this->ended)(ApprovalProcessStatus::Approved))
        ->resume();

    expect($run->activitiesNamed('approvals.escalateStage'))->toHaveCount(1)
        ->and($run->isFinished())->toBeTrue();

    WorkflowRun::end();
    [$run, $workflow] = ($this->openProcess)();

    $run->handle('cancel', $workflow->cancel($this->cancellation))
        ->fireTimer();

    expect($run->waitsFor())->not->toBe('approvals.escalateStage');

    $run->resume(new ApprovalStepResultData(ApprovalStepOutcome::Cancelled, 'event-1'), 'cancel')
        ->resume(($this->ended)(ApprovalProcessStatus::Cancelled))
        ->resume();

    expect($run->activitiesNamed('approvals.escalateStage'))->toBe([])
        ->and($run->resultOf('cancel'))->toBe('cancelled')
        ->and($run->isFinished())->toBeTrue();
});

it('keeps the approval process open for its deadline and its decision when a notification step fails', function (): void {
    $workflow = new ApprovalProcessWorkflow;
    $run = WorkflowRun::start(fn (): Generator => $workflow->run($this->input))
        ->resume(($this->pending)())
        ->fail(new RuntimeException('mail transport unavailable'));

    expect($run->failureOf())->toBeNull()
        ->and($run->waitsFor())->toBe('awaitWithTimeout');

    $run->fireTimer()
        ->resume(new ApprovalEscalationResultData(ApprovalEscalationType::Delegate))
        ->fail(new RuntimeException('mail transport unavailable'));

    expect($run->failureOf())->toBeNull()
        ->and($run->waitsFor())->toBe('approvals.describeProcess');

    $run->resume(($this->pending)(true))
        ->handle('decide', $workflow->decide($this->decision))
        ->resume(new ApprovalStepResultData(ApprovalStepOutcome::Approved, 'event-1'), 'decide')
        ->resume(($this->ended)(ApprovalProcessStatus::Approved))
        ->fail(new RuntimeException('mail transport unavailable'));

    expect($run->failureOf())->toBeNull()
        ->and($run->isFinished())->toBeTrue()
        ->and($run->resultOf('decide'))->toBe('approved')
        ->and($run->activitiesNamed('approvals.notifyStageStarted'))->toHaveCount(1)
        ->and(collect($run->logs)->where('level', 'error')->pluck('context.workflow_id')->all())->toBe(array_fill(0, 3, 'workflow-run-test-id'));
});
