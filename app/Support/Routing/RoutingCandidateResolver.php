<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\DTOs\Routing\RoutingContext;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Governance\CandidateCircleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RoutingCandidateResolver
{
    public function __construct(
        private readonly CandidateCircleResolver $candidateCircleResolver,
        private readonly RoutingStrategyRegistry $routingStrategyRegistry,
        private readonly SkillFilter $skillFilter,
    ) {}

    public function select(CustomRecord $record, RoutingContext $context): ?User
    {
        if ($context->tenantId === '' || $context->tenantId !== $record->tenant_id) {
            Log::warning('Routing selection refused because the context tenant does not match the record.', [
                'context_tenant_id' => $context->tenantId,
                'automation_id' => $context->automationId,
                'node_id' => $context->nodeId,
            ]);

            return null;
        }

        $candidates = $this->candidateCircleResolver->resolve($record, $context->circle, $context->at);

        $skillId = $this->skillFilter->resolveSkillId($record, $context);

        if ($context->requiresSkill() && $skillId === null) {
            return null;
        }

        $candidates = $this->restrict(
            $this->skillFilter->apply($candidates, $skillId),
            $context->restrictToUserIds,
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        return $this->routingStrategyRegistry->handlerFor($context->strategy)->pick($candidates, $context);
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @param  list<string>|null  $restrictToUserIds
     * @return Collection<int, User>
     */
    private function restrict(Collection $candidates, ?array $restrictToUserIds): Collection
    {
        if ($restrictToUserIds === null) {
            return $candidates;
        }

        $allowedIds = array_flip($restrictToUserIds);

        return $candidates
            ->filter(static fn (User $user): bool => isset($allowedIds[(string) $user->getKey()]))
            ->values();
    }
}
