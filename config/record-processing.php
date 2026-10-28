<?php

declare(strict_types=1);

return [
    'activity_timeouts' => ['start_to_close' => 120, 'schedule_to_close' => 600],
    'retry_overrides' => ['max_attempts' => 3, 'initial_interval' => 1, 'backoff_coefficient' => 2.0, 'non_retryable' => []],
    'schedules' => ['relay_immediate' => (bool) env('RECORD_RELAY_IMMEDIATE', true), 'relay_interval' => 60, 'periodic_scan_interval' => 900],
];
