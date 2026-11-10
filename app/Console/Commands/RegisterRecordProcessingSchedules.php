<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Temporal\RecordProcessingScheduleRegistrar;
use Illuminate\Console\Command;

class RegisterRecordProcessingSchedules extends Command
{
    protected $signature = 'records:register-schedules';

    protected $description = 'Register shared record processing and installed module schedules';

    public function handle(RecordProcessingScheduleRegistrar $registrar): int
    {
        $registrar->register();

        foreach (config('modules.schedule_commands', []) as $command) {
            if ($this->call($command) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->info('Record processing Temporal schedules registered.');

        return self::SUCCESS;
    }
}
