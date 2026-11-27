<?php

declare(strict_types=1);

namespace App\Notifications\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalProcessStage;
use App\Notifications\Abstracts\ApprovalNotification;

class ApprovalEscalatedNotification extends ApprovalNotification
{
    public function __construct(
        private readonly ApprovalProcessStage $stage,
        private readonly ApprovalEscalationType $escalationType,
    ) {
        parent::__construct(self::processOf($stage));
    }

    public function type(): string
    {
        return 'approval.escalated';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        $deadline = $this->stage->deadline_at;
        $deadlineLabel = $this->deadlineLabel($deadline);

        $body = sprintf(
            __('i18n.backend.notifications.approvals.approval_escalated_notification.stage_d_of_approval_process_s_was_escalated_s'),
            $this->stage->position,
            $this->presenter()->processReference($this->process),
            $this->escalationType->label(),
        );

        if ($deadlineLabel !== null) {
            $body .= sprintf(__('i18n.backend.notifications.approvals.approval_escalated_notification.the_deadline_expired_on_s_utc'), $deadlineLabel);
        }

        return [
            ...$this->payload(__('i18n.backend.notifications.approvals.approval_escalated_notification.approval_escalated'), $body, $notifiable),
            'approvalProcessStageId' => (string) $this->stage->getKey(),
            'stagePosition' => $this->stage->position,
            'escalationType' => $this->escalationType->value,
            'escalationLabel' => $this->escalationType->label(),
            'deadlineAt' => $deadline?->toISOString(),
        ];
    }
}
