<?php

declare(strict_types=1);

namespace App\Enums\Goals;

enum GoalScopeType: string
{
    case User = 'user';

    case Team = 'team';

    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::User => __('i18n.backend.enums.goals.goal_scope_type.user'),
            self::Team => __('i18n.backend.enums.goals.goal_scope_type.team'),
            self::Tenant => __('i18n.backend.enums.goals.goal_scope_type.tenant'),
        };
    }
}
