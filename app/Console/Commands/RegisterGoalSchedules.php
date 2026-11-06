<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Goals\GoalScheduleRegistrar;
use Illuminate\Console\Command;

class RegisterGoalSchedules extends Command
{
    protected $signature = 'goals:register-schedules';

    protected $description = 'Register the Temporal schedule that drives the goal progress scan';

    public function handle(GoalScheduleRegistrar $registrar): int
    {
        $registrar->register();

        $this->info('Goal Temporal schedule registered.');

        return self::SUCCESS;
    }
}
