<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Goal;
use App\Models\GoalPeriod;
use Carbon\CarbonImmutable;

class RecordGoalProgressAction
{
    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $periodBounds
     * @param  array<string, string>  $triggeredThresholds
     */
    public function execute(
        Goal $goal,
        array $periodBounds,
        string $value,
        CarbonImmutable $now,
        array $triggeredThresholds = [],
    ): GoalPeriod {
        return $goal->periods()->updateOrCreate(
            ['period_start' => $periodBounds['start']],
            [
                'period_end' => $periodBounds['end'],
                'current_value' => $value,
                'calculated_at' => $now,
                'triggered_thresholds' => $triggeredThresholds,
            ],
        );
    }
}
