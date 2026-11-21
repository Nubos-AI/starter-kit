<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum RuleActionType: string
{
    case Notify = 'notify';

    case CreateReminder = 'create_reminder';
}
