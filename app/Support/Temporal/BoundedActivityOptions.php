<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Support\Maintenance\MaintenanceRetryOptions;
use Carbon\CarbonInterval;
use Keepsuit\LaravelTemporal\Builder\ActivityBuilder;
use Keepsuit\LaravelTemporal\Facade\Temporal;

class BoundedActivityOptions
{
    public static function make(CarbonInterval $startToClose): ActivityBuilder
    {
        $scheduleToClose = max((int) $startToClose->totalSeconds, (int) config('temporal.activity_limits.schedule_to_close'));

        return Temporal::newActivity()
            ->withStartToCloseTimeout($startToClose)
            ->withScheduleToCloseTimeout(CarbonInterval::seconds($scheduleToClose))
            ->withRetryOptions(MaintenanceRetryOptions::make());
    }
}
