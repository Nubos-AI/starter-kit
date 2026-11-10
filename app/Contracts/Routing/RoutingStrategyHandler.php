<?php

declare(strict_types=1);

namespace App\Contracts\Routing;

use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\RoutingStrategy;
use App\Models\User;
use Illuminate\Support\Collection;

interface RoutingStrategyHandler
{
    public function strategy(): RoutingStrategy;

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function pick(Collection $candidates, RoutingContext $context): ?User;
}
