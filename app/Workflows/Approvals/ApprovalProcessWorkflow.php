<?php

declare(strict_types=1);

namespace App\Workflows\Approvals;

use App\Contracts\Approvals\ApprovalProcessActivityInterface;
use App\Contracts\Approvals\ApprovalProcessWorkflowInterface;
use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalEscalationResultData;
use App\DTOs\Approvals\ApprovalProcessData;
use App\DTOs\Approvals\ApprovalProcessStateData;
use App\DTOs\Approvals\ApprovalStepResultData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;

class ApprovalProcessWorkflow implements ApprovalProcessWorkflowInterface
{
    private bool $hasPendingChange = true;

    private bool $hasSeenPending = false;

    private bool $hasDecisionInThisRun = false;

    private int $runningCommands = 0;

    private bool $isChangeRunning = false;

    /**
     * @var array<string, true>
     */
    private array $announcedStageIds = [];

    public function run(ApprovalProcessData $input): Generator
    {
        try {
            $state = yield from $this->followUntilEnded($input);

            if ($state->decisionEventId !== null && ($this->hasSeenPending || $this->hasDecisionInThisRun)) {
                yield from $this->notify($input, 'notifyDecision', fn (): mixed => $this->steps()->notifyDecision($input->tenantId, $state->decisionEventId));
            }

            yield Workflow::await(fn (): bool => $this->runningCommands === 0);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Approval process workflow failed.', [
                ...$this->logContext($input),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('Approval process workflow finished.', [
            ...$this->logContext($input),
            'status' => $state->status->value,
        ]);
    }

    public function stageChanged(): void
    {
        $this->hasPendingChange = true;
    }

    public function decide(ApprovalCommandData $command): Generator
    {
        return yield from $this->command(fn (): mixed => $this->steps()->decide($command));
    }

    public function cancel(ApprovalCommandData $command): Generator
    {
        return yield from $this->command(fn (): mixed => $this->steps()->cancel($command));
    }

    /**
     * @param  callable(): mixed  $step
     */
    private function command(callable $step): Generator
    {
        $this->runningCommands++;

        try {
            yield from $this->awaitChangeSlot();

            $this->isChangeRunning = true;

            /** @var ApprovalStepResultData $result */
            $result = yield $step();

            $this->hasDecisionInThisRun = $this->hasDecisionInThisRun || $result->isDecision();
        } finally {
            $this->hasPendingChange = true;
            $this->isChangeRunning = false;
            $this->runningCommands--;
        }

        return $result->outcome->value;
    }

    private function awaitChangeSlot(): Generator
    {
        $hasWaited = false;

        while ($this->isChangeRunning) {
            $hasWaited = true;

            yield Workflow::await(fn (): bool => $this->isChangeSlotFree());
        }

        return $hasWaited;
    }

    private function isChangeSlotFree(): bool
    {
        return !$this->isChangeRunning;
    }

    /**
     * @param  callable(): mixed  $step
     */
    private function notify(ApprovalProcessData $input, string $notification, callable $step): Generator
    {
        try {
            yield $step();
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Approval process notification failed, the process continues.', [
                ...$this->logContext($input),
                'notification' => $notification,
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);
        }
    }

    private function followUntilEnded(ApprovalProcessData $input): Generator
    {
        while (true) {
            $this->hasPendingChange = false;

            /** @var ApprovalProcessStateData $state */
            $state = yield $this->steps()->describeProcess($input);

            if (!$state->isPending()) {
                return $state;
            }

            $this->hasSeenPending = true;

            $stageId = $state->currentStageId;

            if ($stageId !== null && !isset($this->announcedStageIds[$stageId])) {
                $this->announcedStageIds[$stageId] = true;

                yield from $this->notify($input, 'notifyStageStarted', fn (): mixed => $this->steps()->notifyStageStarted($input->tenantId, $stageId));
            }

            $deadline = $state->deadline();

            if (!$state->awaitsDeadline() || !$deadline instanceof CarbonImmutable || $stageId === null) {
                yield Workflow::await(fn (): bool => $this->hasPendingChange);

                continue;
            }

            $now = CarbonImmutable::instance(Workflow::now());
            $remainingSeconds = max(1, (int) ceil($now->diffInSeconds($deadline, false)));

            $hasChanged = yield Workflow::awaitWithTimeout(
                CarbonInterval::seconds($remainingSeconds),
                fn (): bool => $this->hasPendingChange,
            );

            $hasWaitedForChange = yield from $this->awaitChangeSlot();

            if ($hasChanged || $hasWaitedForChange || $this->runningCommands > 0) {
                continue;
            }

            $this->isChangeRunning = true;

            try {
                /** @var ApprovalEscalationResultData $escalation */
                $escalation = yield $this->steps()->escalateStage($input->tenantId, $stageId);
            } finally {
                $this->isChangeRunning = false;
            }

            $escalationType = $escalation->appliedType;

            if ($escalationType !== null) {
                yield from $this->notify($input, 'notifyEscalation', fn (): mixed => $this->steps()->notifyEscalation($input->tenantId, $stageId, $escalationType->value));
            }
        }
    }

    /**
     * @return ActivityProxy<ApprovalProcessActivityInterface>
     */
    private function steps(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->build(ApprovalProcessActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, approval_process_id: string}
     */
    private function logContext(ApprovalProcessData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'approval_process_id' => $input->processId,
        ];
    }
}
