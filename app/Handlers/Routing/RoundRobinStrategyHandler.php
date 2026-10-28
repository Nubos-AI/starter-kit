<?php

declare(strict_types=1);

namespace App\Handlers\Routing;

use App\Contracts\Routing\RoutingStrategyHandler;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\RoutingStrategy;
use App\Models\RoutingCursor;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RoundRobinStrategyHandler implements RoutingStrategyHandler
{
    public function strategy(): RoutingStrategy
    {
        return RoutingStrategy::RoundRobin;
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function pick(Collection $candidates, RoutingContext $context): ?User
    {
        $ordered = $candidates
            ->sortBy(static fn (User $user): string => (string) $user->getKey(), SORT_STRING)
            ->values();

        if ($ordered->isEmpty()) {
            return null;
        }

        try {
            return $this->advance($ordered, $context);
        } catch (UniqueConstraintViolationException) {
            return $this->advance($ordered, $context);
        }
    }

    /**
     * @param  Collection<int, User>  $ordered
     *
     * @throws Throwable
     */
    private function advance(Collection $ordered, RoutingContext $context): User
    {
        return DB::transaction(function () use ($ordered, $context): User {
            $cursor = RoutingCursor::query()
                ->where('tenant_id', $context->tenantId)
                ->where('automation_id', $context->automationId)
                ->where('node_id', $context->nodeId)
                ->lockForUpdate()
                ->first();

            $picked = $this->nextCandidate($ordered, $cursor?->last_user_id);

            $cursor ??= new RoutingCursor;

            $cursor->fill([
                'tenant_id' => $context->tenantId,
                'automation_id' => $context->automationId,
                'last_user_id' => (string) $picked->getKey(),
                'node_id' => $context->nodeId,
            ]);

            $cursor->save();

            return $picked;
        });
    }

    /**
     * @param  Collection<int, User>  $ordered
     */
    public function nextCandidate(Collection $ordered, ?string $lastUserId): User
    {
        if ($lastUserId !== null) {
            $next = $ordered->first(
                static fn (User $user): bool => strcmp((string) $user->getKey(), $lastUserId) > 0,
            );

            if ($next instanceof User) {
                return $next;
            }
        }

        return $ordered->firstOrFail();
    }
}
