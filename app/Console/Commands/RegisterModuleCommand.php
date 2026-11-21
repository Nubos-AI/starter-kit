<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\RegisterModuleAction;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Throwable;

class RegisterModuleCommand extends Command
{
    protected $signature = 'modules:register {package}';

    protected $description = 'Migrate and install a Composer module and add it to the module registry';

    /**
     * @throws Throwable
     */
    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, RegisterModuleAction $register): int
    {
        $package = (string) $this->argument('package');

        if (!$catalog->has($package)) {
            $this->error("The package {$package} is not an installed module.");

            return self::FAILURE;
        }

        $migrations = $catalog->migrationPath($package);

        if ($migrations !== null && $this->call('migrate', ['--path' => [$migrations], '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $install = $catalog->manifest($package)['install'] ?? null;
        $registry->activate($package);

        try {
            if (is_string($install) && $this->call($install, ['--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
                $registry->deactivate($package);

                return self::FAILURE;
            }

            $register->execute($package);
        } catch (Throwable $exception) {
            $registry->deactivate($package);

            throw $exception;
        }

        $this->info("The module {$package} is registered.");

        return self::SUCCESS;
    }
}
