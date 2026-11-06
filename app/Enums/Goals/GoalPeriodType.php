<?php

declare(strict_types=1);

namespace App\Enums\Goals;

enum GoalPeriodType: string
{
    case Month = 'month';

    case Quarter = 'quarter';

    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Month => __('i18n.backend.enums.goals.goal_period_type.month'),
            self::Quarter => __('i18n.backend.enums.goals.goal_period_type.quarter'),
            self::Year => __('i18n.backend.enums.goals.goal_period_type.year'),
        };
    }
}
