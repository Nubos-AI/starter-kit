<?php

declare(strict_types=1);

namespace App\Activities\Approvals;

use App\Actions\Approvals\CancelApprovalProcessAction;
use App\Actions\Approvals\DecideApprovalAction;
use App\Actions\Approvals\DescribeApprovalProcessAction;
use App\Actions\Approvals\EscalateApprovalStageAction;
use App\Actions\Approvals\NotifyApprovalDecisionAction;
use App\Actions\Approvals\NotifyApprovalEscalationAction;
use App\Actions\Approvals\NotifyApprovalStageStartedAction;
use App\Contracts\Approvals\ApprovalProcessActivityInterface;
use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalEscalationResultData;
use App\DTOs\Approvals\ApprovalProcessData;
use App\DTOs\Approvals\ApprovalProcessStateData;
use App\DTOs\Approvals\ApprovalStepResultData;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\User;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use Carbon\CarbonImmutable;

class ApprovalProcessActivity implements ApprovalProcessActivityInterface
{
    public function __construct(
        private readonly DescribeApprovalProcessAction $describeProcess,
        private readonly DecideApprovalAction $decideApproval,
        private readonly CancelApprovalProcessAction $cancelProcess,
        private readonly NotifyApprovalStageStartedAction $notifyStageStarted,
        private readonly EscalateApprovalStageAction $escalateStage,
        private readonly NotifyApprovalEscalationAction $notifyEscalation,
        private readonly NotifyApprovalDecisionAction $notifyDecision,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function describeProcess(ApprovalProcessData $input): ApprovalProcessStateData
    {
        return $this->steps->run(
            'approvals.describe-process',
            ['tenant_id' => $input->tenantId, 'approval_process_id' => $input->processId],
            fn (): ApprovalProcessStateData => $this->tenantBinder->run($input->tenantId, fn (): ApprovalProcessStateData => $this->describeProcess->execute(
                $this->tenantProcess($input->tenantId, $input->processId),
            )),
        );
    }

    public function decide(ApprovalCommandData $command): ApprovalStepResultData
    {
        return $this->steps->run(
            'approvals.decide',
            $this->commandContext($command),
            fn (): ApprovalStepResultData => $this->tenantBinder->run($command->tenantId, fn (): ApprovalStepResultData => $this->decideApproval->execute(
                $this->tenantUser($command->tenantId, $command->actorId),
                $this->tenantProcess($command->tenantId, $command->processId),
                $command->input,
            )),
        );
    }

    public function cancel(ApprovalCommandData $command): ApprovalStepResultData
    {
        return $this->steps->run(
            'approvals.cancel',
            $this->commandContext($command),
            fn (): ApprovalStepResultData => $this->tenantBinder->run($command->tenantId, fn (): ApprovalStepResultData => $this->cancelProcess->execute(
                $this->tenantUser($command->tenantId, $command->actorId),
                $this->tenantProcess($command->tenantId, $command->processId),
                $command->input,
            )),
        );
    }

    public function notifyStageStarted(string $tenantId, string $stageId): void
    {
        $this->steps->run(
            'approvals.notify-stage-started',
            ['tenant_id' => $tenantId, 'approval_process_stage_id' => $stageId],
            fn () => $this->tenantBinder->run($tenantId, fn () => $this->notifyStageStarted->execute(
                $this->tenantStage($tenantId, $stageId),
            )),
        );
    }

    public function escalateStage(string $tenantId, string $stageId): ApprovalEscalationResultData
    {
        return $this->steps->run(
            'approvals.escalate-stage',
            ['tenant_id' => $tenantId, 'approval_process_stage_id' => $stageId],
            fn (): ApprovalEscalationResultData => $this->tenantBinder->run($tenantId, fn (): ApprovalEscalationResultData => $this->escalateStage->execute(
                $this->tenantStage($tenantId, $stageId),
                CarbonImmutable::now('UTC'),
            )),
        );
    }

    public function notifyEscalation(string $tenantId, string $stageId, string $escalationType): void
    {
        $this->steps->run(
            'approvals.notify-escalation',
            ['tenant_id' => $tenantId, 'approval_process_stage_id' => $stageId, 'escalation_type' => $escalationType],
            fn () => $this->tenantBinder->run($tenantId, fn () => $this->notifyEscalation->execute(
                $this->tenantStage($tenantId, $stageId),
                ApprovalEscalationType::from($escalationType),
            )),
        );
    }

    public function notifyDecision(string $tenantId, string $eventId): void
    {
        $this->steps->run(
            'approvals.notify-decision',
            ['tenant_id' => $tenantId, 'approval_event_id' => $eventId],
            fn () => $this->tenantBinder->run($tenantId, fn () => $this->notifyDecision->execute(
                ApprovalEvent::query()->where('tenant_id', $tenantId)->whereKey($eventId)->firstOrFail(),
            )),
        );
    }

    /**
     * @return array{tenant_id: string, approval_process_id: string, actor_id: string}
     */
    private function commandContext(ApprovalCommandData $command): array
    {
        return [
            'tenant_id' => $command->tenantId,
            'approval_process_id' => $command->processId,
            'actor_id' => $command->actorId,
        ];
    }

    private function tenantProcess(string $tenantId, string $processId): ApprovalProcess
    {
        return ApprovalProcess::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($processId)
            ->firstOrFail();
    }

    private function tenantStage(string $tenantId, string $stageId): ApprovalProcessStage
    {
        return ApprovalProcessStage::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($stageId)
            ->firstOrFail();
    }

    private function tenantUser(string $tenantId, string $userId): User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->firstOrFail();
    }
}
