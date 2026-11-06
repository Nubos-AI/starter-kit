<?php

declare(strict_types=1);

namespace App\Enums\Promotion;

enum PromotionRunStatus: string
{
    case Draft = 'draft';

    case AwaitingApproval = 'awaiting_approval';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Applying = 'applying';

    case Completed = 'completed';

    case Failed = 'failed';

    case RolledBack = 'rolled_back';
}
