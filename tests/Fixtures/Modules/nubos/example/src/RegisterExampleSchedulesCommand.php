<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\nubos\example\src;

use Illuminate\Console\Command;

class RegisterExampleSchedulesCommand extends Command
{
    protected $signature = 'example:register-schedules';

    protected $description = 'Register the Temporal schedules of the example module';

    public function handle(): int
    {
        $this->info('Example schedules registered.');

        return self::SUCCESS;
    }
}
