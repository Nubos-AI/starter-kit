<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\DTOs\Approvals\ApprovalEscalationResultData;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalEscalationResolver;
use App\Support\Approvals\ApprovalEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EscalateApprovalStageAction
{
    public function __construct(
        private readonly ApprovalEscalationResolver $escalationResolver,
        private readonly ApprovalEventRecorder $eventRecorder,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(ApprovalProcessStage $stage, CarbonImmutable $now): ApprovalEscalationResultData
    {
        return DB::transaction(function () use ($stage, $now): ApprovalEscalationResultData {
            $process = ApprovalProcess::query()
                ->where('tenant_id', $stage->tenant_id)
                ->whereKey($stage->approval_process_id)
                ->lockForUpdate()
                ->first();

            if (!$process instanceof ApprovalProcess) {
                return new ApprovalEscalationResultData(null);
            }

            $fresh = ApprovalProcessStage::query()
                ->where('tenant_id', $stage->tenant_id)
                ->whereKey($stage->getKey())
                ->first();

            if (!$fresh instanceof ApprovalProcessStage || !$this->isStillDue($process, $fresh, $now)) {
                return new ApprovalEscalationResultData(null);
            }

            $configured = $this->escalationResolver->configuredTypeFor($fresh);
            $applied = $this->escalationResolver->apply($fresh, $now);

            $fresh->fill(['escalation_applied' => true])->save();

            $this->eventRecorder->record($process, ApprovalEventType::Escalated, [
                'stage' => $fresh,
                'escalation_type' => $applied,
                'payload' => ['delegations' => $fresh->escalation_added_delegations ?? []],
            ]);

            if ($applied !== $configured) {
                $this->eventRecorder->record($process, ApprovalEventType::EscalationBlocked, [
                    'stage' => $fresh,
                    'escalation_type' => $configured,
                    'reason' => __('i18n.backend.support.approvals.approval_deadline_runner.no_deputy_is_available_for_the_expired_stage'),
                ]);
            }

            Log::info('An approval stage was escalated after its deadline.', [
                'tenant_id' => $fresh->tenant_id,
                'approval_process_stage_id' => (string) $fresh->getKey(),
                'escalation_type' => $applied->value,
                'is_blocked' => $applied !== $configured,
            ]);

            return new ApprovalEscalationResultData($applied);
        });
    }

    private function isStillDue(ApprovalProcess $process, ApprovalProcessStage $stage, CarbonImmutable $now): bool
    {
        if ($process->status !== ApprovalProcessStatus::Pending || $process->current_stage_position === null) {
            return false;
        }

        if ($stage->attempt !== $process->attempt || $stage->position !== $process->current_stage_position) {
            return false;
        }

        if ($stage->status !== ApprovalStageStatus::Pending || $stage->escalation_applied) {
            return false;
        }

        return $stage->deadline_at !== null && $stage->deadline_at->lessThanOrEqualTo($now);
    }
}
