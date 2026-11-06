<?php

declare(strict_types=1);

namespace App\Handlers\Routing;

use App\Contracts\Routing\RoutingStrategyHandler;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\RoutingStrategy;
use App\Models\User;
use App\Support\Governance\AssignmentLoadCounter;
use Illuminate\Support\Collection;

class LoadStrategyHandler implements RoutingStrategyHandler
{
    public function __construct(private readonly AssignmentLoadCounter $assignmentLoadCounter) {}

    public function strategy(): RoutingStrategy
    {
        return RoutingStrategy::Load;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function pick(Collection $candidates, RoutingContext $context): ?User
    {
        /** @var list<string> $candidateIds */
        $candidateIds = array_values(
            $candidates->map(static fn (User $user): string => (string) $user->getKey())->all(),
        );

        if ($candidateIds === []) {
            return null;
        }

        $loads = $this->assignmentLoadCounter->countFor($candidateIds, $context->tenantId);

        return $candidates->sortBy(static fn (User $user): array => [
            $loads[(string) $user->getKey()] ?? 0,
            (string) $user->getKey(),
        ])->first();
    }
}
