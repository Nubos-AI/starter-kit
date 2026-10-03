<?php

declare(strict_types=1);

namespace App\Handlers\Routing;

use App\Contracts\Routing\RoutingStrategyHandler;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\RoutingStrategy;
use App\Models\User;
use Illuminate\Support\Collection;

class RandomStrategyHandler implements RoutingStrategyHandler
{
    public function strategy(): RoutingStrategy
    {
        return RoutingStrategy::Random;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function pick(Collection $candidates, RoutingContext $context): ?User
    {
        $pool = $candidates->values();

        if ($pool->isEmpty()) {
            return null;
        }

        return $pool->get(random_int(0, $pool->count() - 1));
    }
}
