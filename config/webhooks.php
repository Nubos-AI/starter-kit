<?php

declare(strict_types=1);

use App\Exceptions\Webhooks\SsrfBlockedException;

return [
    'events' => ['record.created', 'record.updated', 'record.deleted', 'record.restored'],

    'delivery' => [
        'start_to_close_timeout' => (int) env('WEBHOOK_DELIVERY_TIMEOUT', 30),
    ],

    'retry' => [
        'max_attempts' => (int) env('WEBHOOK_RETRY_MAX_ATTEMPTS', 15),
        'initial_interval' => (int) env('WEBHOOK_RETRY_INITIAL_INTERVAL', 1),
        'backoff_coefficient' => (float) env('WEBHOOK_RETRY_BACKOFF_COEFFICIENT', 2.0),
        'maximum_interval' => (int) env('WEBHOOK_RETRY_MAXIMUM_INTERVAL', 3600),
        'non_retryable' => [
            SsrfBlockedException::class,
        ],
    ],

    'auto_disable' => [
        'consecutive_failure_threshold' => (int) env('WEBHOOK_DISABLE_THRESHOLD', 15),
    ],
];
