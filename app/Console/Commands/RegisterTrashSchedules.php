<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Trash\TrashScheduleRegistrar;
use Illuminate\Console\Command;

class RegisterTrashSchedules extends Command
{
    protected $signature = 'trash:register-schedules';

    protected $description = 'Register the Temporal schedule that purges trashed records once their retention period has elapsed';

    public function handle(TrashScheduleRegistrar $registrar): int
    {
        $registrar->register();

        $this->info('Trash Temporal schedule registered.');

        return self::SUCCESS;
    }
}
