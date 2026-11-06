<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncModulesCommand extends Command
{
    protected $signature = 'modules:sync';

    protected $description = 'Register every installed module that is not in the module registry yet and update every registered module whose version changed';

    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, DatabaseManager $database): int
    {
        try {
            $registered = $database->connection()->getSchemaBuilder()->hasTable('modules') ? $registry->all() : [];
            $storedVersions = array_combine($registered, array_map($registry->version(...), $registered));
        } catch (Throwable $exception) {
            $this->warn('Keine Datenbank erreichbar. Neue Module werden beim nächsten modules:sync registriert.');
            Log::warning('Module sync skipped because the module registry could not be read.', ['exception' => $exception]);

            return self::SUCCESS;
        }

        $pending = array_values(array_diff($catalog->all(), $registered));
        $migrations = array_values(array_filter(array_map($catalog->migrationPath(...), $pending)));

        if ($migrations !== [] && $this->call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $result = self::SUCCESS;

        foreach ($catalog->all() as $package) {
            try {
                $command = match (true) {
                    in_array($package, $pending, true) => 'modules:register',
                    array_key_exists($package, $storedVersions) && $storedVersions[$package] !== $catalog->version($package) => 'modules:update',
                    default => null,
                };
            } catch (Throwable $exception) {
                $this->error("Das Manifest des Moduls {$package} ist nicht lesbar.");
                Log::error('The manifest of a module could not be read during module sync.', ['module' => $package, 'exception' => $exception]);
                $result = self::FAILURE;

                continue;
            }

            if ($command !== null && $this->call($command, ['package' => $package, '--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
                $result = self::FAILURE;
            }
        }

        return $result;
    }
}
