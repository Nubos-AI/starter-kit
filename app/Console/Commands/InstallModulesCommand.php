<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleRequirementWriter;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

class InstallModulesCommand extends Command
{
    protected $signature = 'modules:install';

    protected $description = 'Ask which free Nubos packages to install and add them to composer.json';

    public function handle(ModuleRequirementWriter $requirements): int
    {
        /** @var array<string, string> $installable */
        $installable = config('modules.installable', []);

        /** @var list<string> $packages */
        $packages = array_values(multiselect(
            label: 'Welche kostenlosen Nubos-Pakete sollen installiert werden?',
            options: collect($installable)
                ->map(fn (string $description, string $package): string => "{$package} – {$description}")
                ->all(),
            scroll: 10,
        ));

        if ($packages === []) {
            $this->info('Es werden keine zusätzlichen Pakete installiert.');

            return self::SUCCESS;
        }

        $requirements->add($packages);
        $this->info('Die ausgewählten Pakete werden jetzt installiert.');

        return self::SUCCESS;
    }
}
