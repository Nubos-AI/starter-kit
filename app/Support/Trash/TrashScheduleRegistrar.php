<?php

declare(strict_types=1);

namespace App\Support\Trash;

use App\Support\Temporal\TemporalScheduleGateway;
use App\Traits\Temporal\RegistersTemporalSchedules;

class TrashScheduleRegistrar
{
    use RegistersTemporalSchedules;

    public function __construct(private readonly TemporalScheduleGateway $gateway) {}

    public function register(): void
    {
        $this->upsertCronSchedule(
            'trash-purge',
            'PurgeTrashedRecordsWorkflow',
            (string) config('engine.trash.purge_cron'),
            (string) config('engine.trash.purge_timezone'),
        );
    }
}
