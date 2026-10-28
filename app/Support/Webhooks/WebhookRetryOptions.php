<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use Carbon\CarbonInterval;
use Temporal\Common\RetryOptions;
use Throwable;

class WebhookRetryOptions
{
    public static function make(): RetryOptions
    {
        return RetryOptions::new()
            ->withMaximumAttempts(max(0, (int) config('webhooks.retry.max_attempts')))
            ->withInitialInterval(CarbonInterval::seconds((int) config('webhooks.retry.initial_interval')))
            ->withBackoffCoefficient((float) config('webhooks.retry.backoff_coefficient'))
            ->withMaximumInterval(CarbonInterval::seconds((int) config('webhooks.retry.maximum_interval')))
            ->withNonRetryableExceptions(self::nonRetryableExceptions());
    }

    /**
     * @return array<int, class-string<Throwable>>
     */
    private static function nonRetryableExceptions(): array
    {
        $configured = config('webhooks.retry.non_retryable', []);

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, static fn (mixed $class): bool => is_string($class) && is_a($class, Throwable::class, true)));
    }
}
