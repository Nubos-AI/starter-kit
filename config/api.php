<?php

declare(strict_types=1);

return [
    'rate_limit' => [
        'perMinute' => 120,
        'perTenantPerMinute' => 1200,
        'anonPerMinute' => 30,
        'store' => 'redis',
    ],

    'idempotency' => [
        'lock_timeout_seconds' => 300,
        'retention_hours' => 24,
    ],
];
