<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\UnregisterModuleAction;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class UnregisterModuleCommand extends Command
{
    protected $signature = 'modules:unregister {package}';

    protected $description = 'Uninstall a module and remove it from the module registry';

    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, UnregisterModuleAction $unregister): int
    {
        $package = (string) $this->argument('package');

        if (!$registry->has($package)) {
            $this->info("Das Modul {$package} ist nicht registriert.");

            return self::SUCCESS;
        }

        $uninstall = $catalog->has($package) ? $catalog->manifest($package)['uninstall'] ?? null : null;

        if (is_string($uninstall) && $this->call($uninstall, ['--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $unregister->execute($package);
        $this->info("Das Modul {$package} ist ausgetragen.");

        return self::SUCCESS;
    }
}
