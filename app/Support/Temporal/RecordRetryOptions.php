<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Temporal\Common\RetryOptions;
use Throwable;

class RecordRetryOptions
{
    public static function make(): RetryOptions
    {
        return RetryOptions::new()
            ->withMaximumAttempts(max(0, (int) config('record-processing.retry_overrides.max_attempts')))
            ->withInitialInterval((int) config('record-processing.retry_overrides.initial_interval'))
            ->withBackoffCoefficient((float) config('record-processing.retry_overrides.backoff_coefficient'))
            ->withNonRetryableExceptions(self::nonRetryableExceptions());
    }

    /**
     * @return list<class-string<Throwable>>
     */
    private static function nonRetryableExceptions(): array
    {
        $configured = config('record-processing.retry_overrides.non_retryable');
        $exceptions = [];

        foreach (is_array($configured) ? $configured : [] as $candidate) {
            if (is_string($candidate) && is_a($candidate, Throwable::class, true)) {
                $exceptions[] = $candidate;
            }
        }

        return array_values(array_unique($exceptions));
    }
}
