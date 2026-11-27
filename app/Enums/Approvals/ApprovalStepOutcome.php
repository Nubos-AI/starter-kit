<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

enum ApprovalStepOutcome: string
{
    case StageOpen = 'stage_open';

    case NextStageStarted = 'next_stage_started';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';
}
