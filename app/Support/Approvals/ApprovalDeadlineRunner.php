<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApprovalDeadlineRunner
{
    public function __construct(
        private readonly ApprovalEscalationResolver $escalationResolver,
        private readonly ApprovalEventRecorder $eventRecorder,
        private readonly ApprovalNotifier $notifier,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    public function run(CarbonImmutable $now): void
    {
        $checked = 0;
        $skippedLocked = 0;
        $escalated = 0;
        $blocked = 0;
        $failed = 0;
        $lockedTenantIds = array_flip($this->maintenanceLocks->lockedTenantIds());

        $stages = ApprovalProcessStage::withoutTenantScope()
            ->where('status', ApprovalStageStatus::Pending)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', $now)
            ->where('escalation_applied', false)
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($stages as $stage) {
            if (isset($lockedTenantIds[$stage->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            $checked++;

            try {
                /** @var array{type: ApprovalEscalationType, blocked: bool}|null $outcome */
                $outcome = TenantContext::withTenantId(
                    $stage->tenant_id,
                    fn (): ?array => DB::transaction(fn (): ?array => $this->escalate($stage, $now)),
                );
            } catch (Throwable $exception) {
                $failed++;

                Log::error('An approval deadline escalation failed.', [
                    'tenant_id' => $stage->tenant_id,
                    'approval_process_stage_id' => (string) $stage->getKey(),
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($outcome === null) {
                continue;
            }

            $escalated++;

            if ($outcome['blocked']) {
                $blocked++;
            }
        }
    }

    /**
     * @return array{type: ApprovalEscalationType, blocked: bool}|null
     *
     * @throws Throwable
     */
    private function escalate(ApprovalProcessStage $stage, CarbonImmutable $now): ?array
    {
        $process = ApprovalProcess::query()
            ->whereKey($stage->approval_process_id)
            ->lockForUpdate()
            ->first();

        if (!$process instanceof ApprovalProcess) {
            return null;
        }

        $fresh = ApprovalProcessStage::query()->whereKey($stage->getKey())->first();

        if (!$fresh instanceof ApprovalProcessStage || !$this->isStillDue($process, $fresh, $now)) {
            return null;
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

        $this->notifier->notifyEscalation($fresh, $applied);

        return ['type' => $applied, 'blocked' => $applied !== $configured];
    }

    private function isStillDue(
        ApprovalProcess $process,
        ApprovalProcessStage $stage,
        CarbonImmutable $now,
    ): bool {
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
