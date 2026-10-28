<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Traits\Temporal\RegistersTemporalSchedules;

class RecordProcessingScheduleRegistrar
{
    use RegistersTemporalSchedules;

    public function __construct(private readonly TemporalScheduleGateway $gateway) {}

    public function register(): void
    {
        $this->upsertIntervalSchedule(
            'automation-relay',
            'AutomationRelayWorkflow',
            (int) config('record-processing.schedules.relay_interval'),
        );

        $this->upsertIntervalSchedule(
            'periodic-scans',
            'PeriodicScansWorkflow',
            (int) config('record-processing.schedules.periodic_scan_interval'),
        );
    }
}
