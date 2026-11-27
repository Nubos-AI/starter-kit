<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\ForgetModuleAction;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncModulesCommand extends Command
{
    protected $signature = 'modules:sync
        {--skip-without-database : Report an unreachable database as a warning, as the Composer hooks on the host need it}
        {--allow-missing-code : Report registered modules without installed code as a warning, as a Composer run without development packages omits them on purpose}';

    protected $description = 'Register every installed module that is not in the module registry yet and update every registered module whose version changed';

    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, DatabaseManager $database, ForgetModuleAction $forget): int
    {
        try {
            $hasRegistry = $database->connection()->getSchemaBuilder()->hasTable('modules');
            $registered = $hasRegistry ? $registry->all() : [];
            $withdrawn = $hasRegistry ? $registry->withdrawn() : [];
            $storedVersions = array_combine($registered, array_map($registry->version(...), $registered));
        } catch (Throwable $exception) {
            return $this->reportUnreachableRegistry($exception);
        }

        foreach (array_diff($withdrawn, $catalog->all()) as $uninstalled) {
            $forget->execute($uninstalled);
        }

        $pending = array_values(array_diff($catalog->all(), $registered, $withdrawn));
        $migrations = array_values(array_filter(array_map($catalog->migrationPath(...), $pending)));

        if ($migrations !== [] && $this->call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $result = self::SUCCESS;
        $registeredNow = [];

        foreach ($catalog->all() as $package) {
            try {
                $command = match (true) {
                    in_array($package, $pending, true) => 'modules:register',
                    array_key_exists($package, $storedVersions) && $storedVersions[$package] !== $catalog->version($package) => 'modules:update',
                    array_key_exists($package, $storedVersions) && array_intersect($catalog->dependencies($package), $registeredNow) !== [] => 'modules:update',
                    default => null,
                };
            } catch (Throwable $exception) {
                $this->error("The manifest of module {$package} is not readable.");
                Log::error('The manifest of a module could not be read during module sync.', ['module' => $package, 'exception' => $exception]);
                $result = self::FAILURE;

                continue;
            }

            if ($command === null) {
                continue;
            }

            if ($this->call($command, ['package' => $package, '--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
                $result = self::FAILURE;

                continue;
            }

            if ($command === 'modules:register') {
                $registeredNow[] = $package;
            }
        }

        return $this->reportModulesWithoutCode(array_values(array_diff($registered, $catalog->all()))) ?? $result;
    }

    private function reportUnreachableRegistry(Throwable $exception): int
    {
        if ($this->option('skip-without-database')) {
            $this->warn('No database reachable. New modules will be registered on the next modules:sync.');
            Log::warning('Module sync skipped because the module registry could not be read.', ['exception' => $exception]);

            return self::SUCCESS;
        }

        $this->error('No database reachable. Check the database connection in .env and run modules:sync again.');
        Log::error('Module sync failed because the module registry could not be read.', ['exception' => $exception]);

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $modules
     */
    private function reportModulesWithoutCode(array $modules): ?int
    {
        if ($modules === []) {
            return null;
        }

        if ($this->option('allow-missing-code')) {
            foreach ($modules as $module) {
                $this->warn("The module {$module} is registered, but its code is missing from this installation without development packages. Its data is kept.");
            }

            Log::warning('Registered modules have no installed code in a Composer run without development packages.', ['modules' => $modules]);

            return null;
        }

        foreach ($modules as $module) {
            $this->error("The module {$module} is registered, but its code is not installed. Install it again (composer require {$module}) or unregister it (php artisan modules:unregister {$module}).");
        }

        Log::error('Registered modules have no installed code.', ['modules' => $modules]);

        return self::FAILURE;
    }
}
