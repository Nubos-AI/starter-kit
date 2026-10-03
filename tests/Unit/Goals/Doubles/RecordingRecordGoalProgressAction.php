<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Actions\Goals\RecordGoalProgressAction;
use App\Models\Goal;
use App\Models\GoalPeriod;
use Carbon\CarbonImmutable;
use Tests\Support\ModelStub;

class RecordingRecordGoalProgressAction extends RecordGoalProgressAction
{
    /**
     * @var list<array{goalId: string, value: string, start: string, end: string, thresholds: array<string, string>}>
     */
    public array $calls = [];

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
        $this->calls[] = [
            'goalId' => (string) $goal->getKey(),
            'value' => $value,
            'start' => $periodBounds['start']->toIso8601String(),
            'end' => $periodBounds['end']->toIso8601String(),
            'thresholds' => $triggeredThresholds,
        ];

        return ModelStub::make(GoalPeriod::class, [
            'goal_id' => (string) $goal->getKey(),
            'tenant_id' => (string) $goal->tenant_id,
        ]);
    }
}
