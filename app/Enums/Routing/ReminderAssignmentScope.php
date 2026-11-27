<?php

declare(strict_types=1);

namespace App\Enums\Routing;

enum ReminderAssignmentScope: string
{
    case OpenTasks = 'open_tasks';

    case LatestOpen = 'latest_open';
}
