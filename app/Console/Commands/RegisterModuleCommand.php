<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\RegisterModuleAction;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Console\Command;

class RegisterModuleCommand extends Command
{
    protected $signature = 'modules:register {package}';

    protected $description = 'Migrate and install a Composer module and add it to the module registry';

    public function handle(ModuleCatalog $catalog, RegisterModuleAction $register): int
    {
        $package = (string) $this->argument('package');

        if (!$catalog->has($package)) {
            $this->error("Das Paket {$package} ist kein installiertes Modul.");

            return self::FAILURE;
        }

        $migrations = $catalog->migrationPath($package);

        if ($migrations !== null && $this->call('migrate', ['--path' => [$migrations], '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $install = $catalog->manifest($package)['install'] ?? null;

        if (is_string($install) && $this->call($install, ['--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $register->execute($package);
        $this->info("Das Modul {$package} ist registriert.");

        return self::SUCCESS;
    }
}
