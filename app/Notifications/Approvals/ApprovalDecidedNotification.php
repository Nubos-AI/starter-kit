<?php

declare(strict_types=1);

namespace App\Notifications\Approvals;

use App\Models\ApprovalEvent;
use App\Notifications\Abstracts\ApprovalNotification;

class ApprovalDecidedNotification extends ApprovalNotification
{
    public function __construct(private readonly ApprovalEvent $event)
    {
        parent::__construct(self::processOf($event));
    }

    public function type(): string
    {
        return 'approval.decided';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        $processReference = $this->presenter()->processReference($this->process);
        $outcomeLabel = $this->event->type->label();
        $decidedBy = $this->displayName($this->event->actor_id);
        $onBehalfOf = $this->displayName($this->event->on_behalf_of_id);
        $reason = $this->event->reason;

        $body = match (true) {
            $decidedBy === null => sprintf(
                __('i18n.backend.notifications.approvals.approval_decided_notification.approval_process_s_was_decided_s'),
                $processReference,
                $outcomeLabel,
            ),
            $onBehalfOf === null => sprintf(
                __('i18n.backend.notifications.approvals.approval_decided_notification.s_decided_approval_process_s_s'),
                $decidedBy,
                $processReference,
                $outcomeLabel,
            ),
            default => sprintf(
                __('i18n.backend.notifications.approvals.approval_decided_notification.s_decided_approval_process_s_on_behalf_of_s'),
                $decidedBy,
                $processReference,
                $onBehalfOf,
                $outcomeLabel,
            ),
        };

        if ($reason !== null && $reason !== '') {
            $body .= sprintf(__('i18n.backend.notifications.approvals.approval_decided_notification.reason_s'), $reason);
        }

        return [
            ...$this->payload(__('i18n.backend.notifications.approvals.approval_decided_notification.approval_process_decided'), $body, $notifiable),
            'approvalEventId' => (string) $this->event->getKey(),
            'outcome' => $this->event->type->value,
            'outcomeLabel' => $outcomeLabel,
            'decidedBy' => $decidedBy,
            'onBehalfOf' => $onBehalfOf,
            'reason' => $reason,
        ];
    }

    private function displayName(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        return $this->subjects()->displayName($this->event->tenant_id, $userId);
    }
}
