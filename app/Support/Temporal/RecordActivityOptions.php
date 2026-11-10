<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Carbon\CarbonInterval;
use Keepsuit\LaravelTemporal\Builder\ActivityBuilder;
use Keepsuit\LaravelTemporal\Facade\Temporal;

class RecordActivityOptions
{
    public static function make(): ActivityBuilder
    {
        return Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds((int) config('record-processing.activity_timeouts.start_to_close')))
            ->withScheduleToCloseTimeout(CarbonInterval::seconds((int) config('record-processing.activity_timeouts.schedule_to_close')))
            ->withRetryOptions(RecordRetryOptions::make());
    }
}
