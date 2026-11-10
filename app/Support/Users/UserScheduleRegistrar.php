<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Support\Temporal\TemporalScheduleGateway;
use App\Traits\Temporal\RegistersTemporalSchedules;

class UserScheduleRegistrar
{
    use RegistersTemporalSchedules;

    public function __construct(private readonly TemporalScheduleGateway $gateway) {}

    public function register(): void
    {
        $this->upsertCronSchedule(
            'unverified-registration-purge',
            'PurgeUnverifiedRegistrationsWorkflow',
            (string) config('users.registration.purge_cron'),
            (string) config('users.registration.purge_timezone'),
        );
    }

    private function executionTimeoutConfigKey(): string
    {
        return 'temporal.schedules';
    }
}
