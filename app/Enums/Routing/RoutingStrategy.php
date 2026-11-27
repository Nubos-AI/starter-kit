<?php

declare(strict_types=1);

namespace App\Enums\Routing;

enum RoutingStrategy: string
{
    case RoundRobin = 'round_robin';

    case Load = 'load';

    case Skill = 'skill';

    case Random = 'random';

    public function label(): string
    {
        return match ($this) {
            self::RoundRobin => __('i18n.backend.enums.routing.routing_strategy.round_robin'),
            self::Load => __('i18n.backend.enums.routing.routing_strategy.load_balancing'),
            self::Skill => __('i18n.backend.enums.routing.routing_strategy.skill_based_distribution'),
            self::Random => __('i18n.backend.enums.routing.routing_strategy.random'),
        };
    }
}
