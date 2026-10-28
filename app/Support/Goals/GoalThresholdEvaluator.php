<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Models\Goal;
use App\Models\GoalPeriod;
use Carbon\CarbonImmutable;

class GoalThresholdEvaluator
{
    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $periodBounds
     * @return array{thresholds: array<string, string>, fire: list<string>}
     */
    public function evaluate(Goal $goal, array $periodBounds, string $currentValue): array
    {
        return ['thresholds' => [], 'fire' => []];
    }

    /** @param list<string> $automationIds */
    public function startRuns(Goal $goal, GoalPeriod $period, array $automationIds): void {}
}
