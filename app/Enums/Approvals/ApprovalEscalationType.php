<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalEscalationType: string
{
    case Delegate = 'delegate';

    case WidenCircle = 'widen_circle';

    case NotifyAgain = 'notify_again';

    public function label(): string
    {
        return match ($this) {
            self::Delegate => __('i18n.backend.enums.approvals.approval_escalation_type.forward_to_the_deputy'),
            self::WidenCircle => __('i18n.backend.enums.approvals.approval_escalation_type.expand_candidate_pool'),
            self::NotifyAgain => __('i18n.backend.enums.approvals.approval_escalation_type.notify_again'),
        };
    }
}
