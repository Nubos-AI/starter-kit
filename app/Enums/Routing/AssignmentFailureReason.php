<?php

declare(strict_types=1);

namespace App\Enums\Routing;

enum AssignmentFailureReason: string
{
    case NoRecord = 'no_record';

    case NoCandidate = 'no_candidate';

    case NoOpenProcess = 'no_open_process';

    case NoCurrentStage = 'no_current_stage';

    case AlreadyAssigned = 'already_assigned';

    case NoEligibleApprover = 'no_eligible_approver';

    case NoOpenReminderTask = 'no_open_reminder_task';

    case CandidateWithoutTeam = 'candidate_without_team';
}
