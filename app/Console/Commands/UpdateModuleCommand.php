<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\RegisterModuleAction;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleExecutionGate;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateModuleCommand extends Command
{
    protected $signature = 'modules:update {package? : The registered module to update, every registered module when omitted}';

    protected $description = 'Migrate registered modules, run their update command and store their installed version';

    private string $updateHookPattern = '/^[a-z0-9-]+:update$/';

    public function handle(ModuleCatalog $catalog, ModuleRegistry $registry, RegisterModuleAction $register, ModuleExecutionGate $executionGate): int
    {
        $package = $this->argument('package');

        if (!is_string($package) || $package === '') {
            $result = self::SUCCESS;

            foreach (array_intersect($catalog->all(), $registry->all()) as $module) {
                if ($this->update($module, $catalog, $registry, $register, $executionGate) !== self::SUCCESS) {
                    $result = self::FAILURE;
                }
            }

            return $result;
        }

        if (!$catalog->has($package)) {
            $this->error("Das Paket {$package} ist kein installiertes Modul.");

            return self::FAILURE;
        }

        if (!$registry->has($package)) {
            $this->error("Das Modul {$package} ist nicht registriert.");

            return self::FAILURE;
        }

        return $this->update($package, $catalog, $registry, $register, $executionGate);
    }

    private function update(string $package, ModuleCatalog $catalog, ModuleRegistry $registry, RegisterModuleAction $register, ModuleExecutionGate $executionGate): int
    {
        $context = ['module' => $package, 'from_version' => $registry->version($package), 'to_version' => $catalog->version($package)];

        if (!$executionGate->enabled($package)) {
            $this->warn("Das Modul {$package} ist deaktiviert und wird nicht aktualisiert.");
            Log::warning('Module update skipped because the module is disabled.', $context);

            return self::SUCCESS;
        }

        try {
            $update = $catalog->manifest($package)['update'] ?? null;

            if (is_string($update) && preg_match($this->updateHookPattern, $update) !== 1) {
                return $this->reportFailure([...$context, 'step' => $update]);
            }

            $migrations = $catalog->migrationPath($package);

            if ($migrations !== null && $this->call('migrate', ['--path' => [$migrations], '--realpath' => true, '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                return $this->reportFailure([...$context, 'step' => 'migrate']);
            }

            if (is_string($update) && $this->call($update, ['--no-interaction' => !$this->input->isInteractive()]) !== self::SUCCESS) {
                return $this->reportFailure([...$context, 'step' => $update]);
            }

            $register->execute($package);
        } catch (Throwable $exception) {
            return $this->reportFailure([...$context, 'exception' => $exception]);
        }

        Log::info('Module updated.', $context);
        $this->info("Das Modul {$package} ist auf Version {$this->describe($context['to_version'])} aktualisiert.");

        return self::SUCCESS;
    }

    /**
     * @param  array{module: string, from_version: string|null, to_version: string|null, step?: string, exception?: Throwable}  $context
     */
    private function reportFailure(array $context): int
    {
        Log::error('Module update failed; the stored version stays unchanged.', $context);
        $this->error("Die Aktualisierung des Moduls {$context['module']} von Version {$this->describe($context['from_version'])} auf {$this->describe($context['to_version'])} ist fehlgeschlagen.");

        return self::FAILURE;
    }

    private function describe(?string $version): string
    {
        return $version ?? 'unbekannt';
    }
}
