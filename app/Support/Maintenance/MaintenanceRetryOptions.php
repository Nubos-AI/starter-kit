<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use Temporal\Common\RetryOptions;
use Throwable;

class MaintenanceRetryOptions
{
    public static function make(): RetryOptions
    {
        return RetryOptions::new()
            ->withMaximumAttempts(max(1, (int) config('temporal.activity_limits.maximum_attempts')))
            ->withNonRetryableExceptions([TenantUnderMaintenanceException::class, ...self::configuredNonRetryableExceptions()]);
    }

    /**
     * @return list<class-string<Throwable>>
     */
    private static function configuredNonRetryableExceptions(): array
    {
        $configured = config('temporal.activity_limits.non_retryable');
        $exceptions = [];

        foreach (is_array($configured) ? $configured : [] as $candidate) {
            if (is_string($candidate) && is_a($candidate, Throwable::class, true) && $candidate !== TenantUnderMaintenanceException::class) {
                $exceptions[] = $candidate;
            }
        }

        return $exceptions;
    }
}
