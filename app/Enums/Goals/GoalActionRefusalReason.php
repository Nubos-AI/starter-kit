<?php

declare(strict_types=1);

namespace App\Enums\Goals;

enum GoalActionRefusalReason: string
{
    case NotOwner = 'not_owner';

    case NotVisible = 'not_visible';

    case GroupedReport = 'grouped_report';
}
