<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalProcessStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('i18n.backend.enums.approvals.approval_process_status.open'),
            self::Approved => __('i18n.backend.enums.approvals.approval_process_status.approved'),
            self::Rejected => __('i18n.backend.enums.approvals.approval_process_status.rejected'),
            self::Cancelled => __('i18n.backend.enums.approvals.approval_process_status.cancelled'),
        };
    }
}
