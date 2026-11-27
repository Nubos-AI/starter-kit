<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalEventType: string
{
    case Started = 'started';

    case StageStarted = 'stage_started';

    case Assigned = 'assigned';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Escalated = 'escalated';

    case EscalationBlocked = 'escalation_blocked';

    case Invalidated = 'invalidated';

    case TransitionDeferred = 'transition_deferred';

    case Cancelled = 'cancelled';

    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Started => __('i18n.backend.enums.approvals.approval_event_type.process_started'),
            self::StageStarted => __('i18n.backend.enums.approvals.approval_event_type.stage_started'),
            self::Assigned => __('i18n.backend.enums.approvals.approval_event_type.approver_assigned'),
            self::Approved => __('i18n.backend.enums.approvals.approval_event_type.approved'),
            self::Rejected => __('i18n.backend.enums.approvals.approval_event_type.rejected'),
            self::Escalated => __('i18n.backend.enums.approvals.approval_event_type.elevated'),
            self::EscalationBlocked => __('i18n.backend.enums.approvals.approval_event_type.escalation_not_possible'),
            self::Invalidated => __('i18n.backend.enums.approvals.approval_event_type.invalidated_by_a_change'),
            self::TransitionDeferred => __('i18n.backend.enums.approvals.approval_event_type.transition_deferred'),
            self::Cancelled => __('i18n.backend.enums.approvals.approval_event_type.cancelled'),
            self::Completed => __('i18n.backend.enums.approvals.approval_event_type.process_completed'),
        };
    }
}
