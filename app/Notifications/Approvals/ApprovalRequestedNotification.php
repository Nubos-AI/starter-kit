<?php

declare(strict_types=1);

namespace App\Notifications\Approvals;

use App\Models\ApprovalProcessStage;
use App\Notifications\Abstracts\ApprovalNotification;
use App\Support\Approvals\ApprovalRecordContext;

class ApprovalRequestedNotification extends ApprovalNotification
{
    public function __construct(private readonly ApprovalProcessStage $stage)
    {
        parent::__construct(self::processOf($stage));
    }

    public function type(): string
    {
        return 'approval.requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        $context = app(ApprovalRecordContext::class);
        $anchorLabel = $this->presenter()->referenceLabel($this->process);
        $fromStage = $context->stageLabel($this->process->from_stage_id);
        $toStage = $context->stageLabel($this->process->to_stage_id);
        $deadline = $this->stage->deadline_at;
        $deadlineLabel = $this->deadlineLabel($deadline);

        $edge = $fromStage === null
            ? (string) $toStage
            : $fromStage.' → '.$toStage;

        $body = $this->process->record_id === null
            ? sprintf(
                __('i18n.backend.notifications.approvals.approval_requested_notification.your_approval_is_required_for_process_s_stage_d'),
                $anchorLabel,
                $this->stage->position,
            )
            : sprintf(
                __('i18n.backend.notifications.approvals.approval_requested_notification.the_transition_2_s_must_be_approved_for_record'),
                $anchorLabel,
                $edge,
                $this->stage->position,
            );

        if ($deadlineLabel !== null) {
            $body .= sprintf(__('i18n.backend.notifications.approvals.approval_requested_notification.please_decide_by_s_utc'), $deadlineLabel);
        }

        return [
            ...$this->payload(__('i18n.backend.notifications.approvals.approval_requested_notification.your_approval_is_needed'), $body, $notifiable),
            'approvalProcessStageId' => (string) $this->stage->getKey(),
            'stagePosition' => $this->stage->position,
            'fromStage' => $fromStage,
            'toStage' => $toStage,
            'deadlineAt' => $deadline?->toISOString(),
        ];
    }
}
