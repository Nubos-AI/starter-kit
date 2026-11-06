<?php

declare(strict_types=1);

namespace App\DTOs\Routing;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Routing\RoutingStrategy;
use Carbon\CarbonImmutable;

readonly class RoutingContext
{
    /**
     * @param  list<string>|null  $restrictToUserIds
     */
    public function __construct(
        public string $tenantId,
        public string $automationId,
        public string $nodeId,
        public RoutingStrategy $strategy,
        public CandidateCircle $circle,
        public ?string $skillId,
        public ?string $skillFieldKey,
        public CarbonImmutable $at,
        public ?array $restrictToUserIds = null,
    ) {}

    public function requiresSkill(): bool
    {
        return $this->skillId !== null || $this->skillFieldKey !== null;
    }
}
