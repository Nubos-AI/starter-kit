<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Users\UserScheduleRegistrar;
use Illuminate\Console\Command;

class RegisterUserSchedules extends Command
{
    protected $signature = 'users:register-schedules';

    protected $description = 'Register the Temporal schedule that purges self registrations whose email address was never verified';

    public function handle(UserScheduleRegistrar $registrar): int
    {
        $registrar->register();

        $this->info('User Temporal schedule registered.');

        return self::SUCCESS;
    }
}
