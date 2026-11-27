<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\DTOs\Approvals\ApprovalProcessStateData;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;

class DescribeApprovalProcessAction
{
    public function execute(ApprovalProcess $process): ApprovalProcessStateData
    {
        $stage = $process->status === ApprovalProcessStatus::Pending ? $process->currentStage() : null;

        return new ApprovalProcessStateData(
            status: $process->status,
            currentStageId: $stage instanceof ApprovalProcessStage ? (string) $stage->getKey() : null,
            deadlineAt: $stage?->deadline_at?->toIso8601String(),
            isEscalationApplied: $stage instanceof ApprovalProcessStage && $stage->escalation_applied,
            decisionEventId: $this->decisionEventId($process),
        );
    }

    private function decisionEventId(ApprovalProcess $process): ?string
    {
        $decision = match ($process->status) {
            ApprovalProcessStatus::Approved => ApprovalEventType::Approved,
            ApprovalProcessStatus::Rejected => ApprovalEventType::Rejected,
            default => null,
        };

        if ($decision === null) {
            return null;
        }

        $event = $process->events()
            ->where('type', $decision->value)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $event instanceof ApprovalEvent ? (string) $event->getKey() : null;
    }
}
