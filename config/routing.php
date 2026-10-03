<?php

declare(strict_types=1);

use App\Enums\Routing\RoutingStrategy;
use App\Handlers\Routing\LoadStrategyHandler;
use App\Handlers\Routing\RandomStrategyHandler;
use App\Handlers\Routing\RoundRobinStrategyHandler;
use App\Handlers\Routing\SkillStrategyHandler;

return [
    'strategy_handlers' => [
        RoutingStrategy::RoundRobin->value => RoundRobinStrategyHandler::class,
        RoutingStrategy::Load->value => LoadStrategyHandler::class,
        RoutingStrategy::Skill->value => SkillStrategyHandler::class,
        RoutingStrategy::Random->value => RandomStrategyHandler::class,
    ],
];
