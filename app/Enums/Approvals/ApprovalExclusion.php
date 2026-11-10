<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalExclusion: string
{
    case Trigger = 'trigger';

    case LastEditor = 'last_editor';

    case Creator = 'creator';

    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Trigger => __('i18n.backend.enums.approvals.approval_exclusion.process_initiator'),
            self::LastEditor => __('i18n.backend.enums.approvals.approval_exclusion.last_editor_of_a_checked_field'),
            self::Creator => __('i18n.backend.enums.approvals.approval_exclusion.record_creator'),
            self::Owner => __('i18n.backend.enums.approvals.approval_exclusion.record_owner'),
        };
    }
}
