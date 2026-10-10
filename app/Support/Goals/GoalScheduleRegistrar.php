<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Support\Temporal\TemporalScheduleGateway;
use App\Traits\Temporal\RegistersTemporalSchedules;

class GoalScheduleRegistrar
{
    use RegistersTemporalSchedules;

    public function __construct(private readonly TemporalScheduleGateway $gateway) {}

    public function register(): void
    {
        $this->upsertIntervalSchedule(
            'goal-progress',
            'GoalProgressScanWorkflow',
            (int) config('reports.goal_progress_interval'),
        );
    }
}
