<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalQuorumType: string
{
    case Any = 'any';

    case AtLeastN = 'at_least_n';

    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Any => __('i18n.backend.enums.approvals.approval_quorum_type.one_approval_is_sufficient'),
            self::AtLeastN => __('i18n.backend.enums.approvals.approval_quorum_type.minimum_number_of_approvals'),
            self::All => __('i18n.backend.enums.approvals.approval_quorum_type.everyone_must_approve'),
        };
    }
}
