<?php

declare(strict_types=1);

return [
    'k_anonymity_threshold' => (int) env('REPORTS_K_ANONYMITY_THRESHOLD', 5),

    'max_categories' => (int) env('REPORTS_MAX_CATEGORIES', 5),

    'timezone' => (string) env('REPORTS_TIMEZONE', 'Europe/Berlin'),

    'goal_progress_interval' => (int) env('REPORTS_GOAL_PROGRESS_INTERVAL', 300),

    'index_maintenance' => [
        'enabled' => (bool) env('REPORTS_INDEX_MAINTENANCE', true),

        'start_to_close' => (int) env('REPORTS_INDEX_START_TO_CLOSE', 3600),

        'retry' => [
            'max_attempts' => (int) env('REPORTS_INDEX_RETRY_MAX_ATTEMPTS', 3),

            'initial_interval' => (int) env('REPORTS_INDEX_RETRY_INITIAL_INTERVAL', 30),

            'backoff_coefficient' => (float) env('REPORTS_INDEX_RETRY_BACKOFF_COEFFICIENT', 2.0),

            'maximum_interval' => (int) env('REPORTS_INDEX_RETRY_MAXIMUM_INTERVAL', 600),
        ],
    ],
];
