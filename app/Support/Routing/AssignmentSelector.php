<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\DTOs\Governance\CandidateCircle;
use App\DTOs\Routing\AssignmentOutcome;
use App\DTOs\Routing\AssignmentRequest;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Routing\AssignmentFailureReason;
use App\Models\CustomRecord;
use App\Models\User;

class AssignmentSelector
{
    public function __construct(private readonly RoutingCandidateResolver $routingCandidateResolver) {}

    /**
     * @param  list<string>|null  $restrictToUserIds
     */
    public function select(
        CustomRecord $record,
        AssignmentRequest $request,
        ?array $restrictToUserIds = null,
        bool $restrictionAppliesToFallback = true,
    ): AssignmentOutcome {
        $primary = $this->routingCandidateResolver->select(
            $record,
            $this->context($request, $request->circle, $restrictToUserIds),
        );

        if ($primary instanceof User) {
            return AssignmentOutcome::assigned($primary, false);
        }

        $fallbackCircle = $request->fallbackCircle();

        if (!$fallbackCircle instanceof CandidateCircle) {
            return AssignmentOutcome::failed(AssignmentFailureReason::NoCandidate);
        }

        $fallback = $this->routingCandidateResolver->select(
            $record,
            $this->context(
                $request,
                $fallbackCircle,
                $restrictionAppliesToFallback ? $restrictToUserIds : null,
            ),
        );

        return $fallback instanceof User
            ? AssignmentOutcome::assigned($fallback, true)
            : AssignmentOutcome::failed(AssignmentFailureReason::NoCandidate);
    }

    /**
     * @param  list<string>|null  $restrictToUserIds
     */
    private function context(
        AssignmentRequest $request,
        CandidateCircle $circle,
        ?array $restrictToUserIds,
    ): RoutingContext {
        return new RoutingContext(
            tenantId: $request->tenantId,
            automationId: $request->automationId,
            nodeId: $request->nodeId,
            strategy: $request->strategy,
            circle: $circle,
            skillId: $request->skillId,
            skillFieldKey: $request->skillFieldKey,
            at: $request->at,
            restrictToUserIds: $restrictToUserIds,
        );
    }
}
