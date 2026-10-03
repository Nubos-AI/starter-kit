<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalStageStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('i18n.backend.enums.approvals.approval_stage_status.open'),
            self::Approved => __('i18n.backend.enums.approvals.approval_stage_status.approved'),
            self::Rejected => __('i18n.backend.enums.approvals.approval_stage_status.rejected'),
            self::Superseded => __('i18n.backend.enums.approvals.approval_stage_status.superseded'),
        };
    }
}
