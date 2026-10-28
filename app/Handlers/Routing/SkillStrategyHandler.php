<?php

declare(strict_types=1);

namespace App\Handlers\Routing;

use App\Contracts\Routing\RoutingStrategyHandler;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\RoutingStrategy;
use App\Models\User;
use Illuminate\Support\Collection;

class SkillStrategyHandler implements RoutingStrategyHandler
{
    public function __construct(private readonly RandomStrategyHandler $randomStrategyHandler) {}

    public function strategy(): RoutingStrategy
    {
        return RoutingStrategy::Skill;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function pick(Collection $candidates, RoutingContext $context): ?User
    {
        return $this->randomStrategyHandler->pick($candidates, $context);
    }
}
