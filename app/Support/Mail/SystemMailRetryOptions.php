<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Carbon\CarbonInterval;
use Temporal\Common\RetryOptions;

class SystemMailRetryOptions
{
    public static function make(): RetryOptions
    {
        return RetryOptions::new()
            ->withMaximumAttempts(max(1, (int) config('mail.system_delivery.max_attempts')))
            ->withInitialInterval(CarbonInterval::seconds(max(1, (int) config('mail.system_delivery.initial_interval_seconds'))))
            ->withBackoffCoefficient(max(1.0, (float) config('mail.system_delivery.backoff_coefficient')))
            ->withMaximumInterval(CarbonInterval::seconds(max(1, (int) config('mail.system_delivery.maximum_interval_seconds'))));
    }

    public static function startToClose(): CarbonInterval
    {
        return CarbonInterval::seconds(max(1, (int) config('mail.system_delivery.start_to_close_seconds')));
    }
}
