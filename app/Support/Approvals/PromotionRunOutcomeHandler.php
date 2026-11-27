<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Contracts\Approvals\ApprovalOutcomeHandler;
use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Enums\Promotion\PromotionRunStatus;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\PromotionRun;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\Log;
use JsonException;

class PromotionRunOutcomeHandler implements ApprovalOutcomeHandler
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    public function anchorType(): string
    {
        return PromotionRun::class;
    }

    /**
     * @throws JsonException
     */
    public function recordDecision(ApprovalProcess $process, ApprovalProcessStage $stage, ApprovalEvent $event): void
    {
        $tenantId = $process->tenant_id;
        $run = $this->auditableRunOf($process);

        if (!$run instanceof PromotionRun) {
            Log::warning('An approval decision on a promotion run was not audited because the run no longer exists.', [
                'tenant_id' => $tenantId,
                'approval_process_id' => (string) $process->getKey(),
                'promotion_run_id' => $process->anchor_id,
            ]);

            return;
        }

        $this->auditor->recordEvent($run, 'operation.promotion_approval_decided', [
            'approvalProcessId' => (string) $process->getKey(),
            'decision' => $event->type->value,
            'stagePosition' => $stage->position,
            'actorId' => $event->actor_id,
            'onBehalfOfId' => $event->on_behalf_of_id,
            'triggeredById' => $run->triggered_by_id,
        ], $tenantId);
    }

    public function applyApproved(ApprovalProcess $process): void
    {
        $run = $this->lockedRunOf($process);

        $run->fill(['status' => PromotionRunStatus::Approved])->save();

        $this->outbox->record(OutboxTopic::PromotionApproved->value, $run->tenant_id, OutboxSubject::of($run));
    }

    public function applyRejected(ApprovalProcess $process, ?string $reason): void
    {
        $this->lockedRunOf($process)->fill([
            'status' => PromotionRunStatus::Rejected,
            'finished_at' => now(),
        ])->save();
    }

    private function auditableRunOf(ApprovalProcess $process): ?PromotionRun
    {
        return PromotionRun::withoutTenantScope()
            ->withTrashed()
            ->whereKey($process->anchor_id)
            ->where('tenant_id', $process->tenant_id)
            ->first();
    }

    private function lockedRunOf(ApprovalProcess $process): PromotionRun
    {
        return PromotionRun::withoutTenantScope()
            ->whereKey($process->anchor_id)
            ->where('tenant_id', $process->tenant_id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
