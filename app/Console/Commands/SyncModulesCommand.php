<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

class SyncModulesCommand extends Command
{
    protected $signature = 'modules:sync';

    protected $description = 'Register every installed module that is not in the module registry yet';

    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, DatabaseManager $database): int
    {
        try {
            $registered = $database->connection()->getSchemaBuilder()->hasTable('modules') ? $registry->all() : [];
        } catch (Throwable) {
            $this->warn('Keine Datenbank erreichbar. Neue Module werden beim nächsten modules:sync registriert.');

            return self::SUCCESS;
        }

        $pending = array_values(array_diff($catalog->all(), $registered));
        $migrations = array_values(array_filter(array_map($catalog->migrationPath(...), $pending)));

        if ($migrations !== [] && $this->call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $result = self::SUCCESS;

        foreach ($pending as $package) {
            if ($this->call('modules:register', ['package' => $package, '--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
                $result = self::FAILURE;
            }
        }

        return $result;
    }
}
