<?php

declare(strict_types=1);

namespace App\Enums\Goals;

enum GoalDirection: string
{
    case AtLeast = 'at_least';

    case AtMost = 'at_most';

    public function label(): string
    {
        return match ($this) {
            self::AtLeast => __('i18n.backend.enums.goals.goal_direction.reach_at_least'),
            self::AtMost => __('i18n.backend.enums.goals.goal_direction.do_not_exceed'),
        };
    }
}
