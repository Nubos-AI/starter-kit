<?php

declare(strict_types=1);

namespace App\Enums\Routing;

enum AssignmentTarget: string
{
    case RecordOwner = 'record_owner';

    case RecordTeam = 'record_team';

    case ReminderAssignee = 'reminder_assignee';

    case ApprovalStage = 'approval_stage';

    public function label(): string
    {
        return match ($this) {
            self::RecordOwner => __('i18n.backend.enums.routing.assignment_target.record_owner'),
            self::RecordTeam => __('i18n.backend.enums.routing.assignment_target.record_s_team'),
            self::ReminderAssignee => __('i18n.backend.enums.routing.assignment_target.reminder_assignee'),
            self::ApprovalStage => __('i18n.backend.enums.routing.assignment_target.stage_approver'),
        };
    }
}
