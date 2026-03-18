<?php

declare(strict_types=1);

namespace Nubos\Init\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class InitCommand extends Command
{
    protected $signature = 'nubos:init';
    protected $description = 'Initialize the Nubos organization structure (Team, Workspace, or Tenant)';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        if ($this->alreadyInitialized()) {
            warning('Nubos is already initialized. Remove config/nubos.php to re-run.');

            return self::FAILURE;
        }

        info('Welcome to Nubos! Let\'s set up your organization structure.');

        $config = $this->gatherConfiguration();

        $this->writeConfig($config);
        $this->copyStubs($config);
        $this->patchUserModel($config);
        $this->patchWebRoutes($config);
        $this->patchBootstrapProviders($config);
        $this->patchDatabaseSeeder($config);
        $this->patchInertiaMiddleware($config);
        $this->patchAppSidebar($config);

        foreach ($config['tenant_substructure'] as $sub) {
            $subConfig = array_merge($config, [
                'organization' => Str::singular($sub),
            ]);
            $this->patchUserModel($subConfig);
            $this->patchBootstrapProviders($subConfig);
            $this->patchDatabaseSeeder($subConfig);
            $this->patchInertiaMiddleware($subConfig);
            $this->patchAppSidebar($subConfig);
        }

        info('Nubos initialized with: ' . $config['organization']);

        return self::SUCCESS;
    }

    private function alreadyInitialized(): bool
    {
        return $this->files->exists(config_path('nubos.php'));
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherConfiguration(): array
    {
        $organization = select(
            label: 'Which organization structure do you need?',
            options: [
                'team' => 'Team — Simple team structure',
                'workspace' => 'Workspace — Workspace structure',
                'tenant' => 'Tenant — Full multi-tenancy',
            ],
        );

        $config = [
            'organization' => $organization,
            'database_strategy' => 'single',
            'subdomains' => false,
            'tenant_substructure' => [],
        ];

        if ($organization === 'tenant') {
            $config = array_merge($config, $this->gatherTenantConfiguration());
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherTenantConfiguration(): array
    {
        $databaseStrategy = select(
            label: 'Database strategy?',
            options: [
                'single' => 'Single Database — Automatic scoping per tenant',
                'multi' => 'Multi Database — Separate database per tenant',
            ],
        );

        $subdomains = confirm(
            label: 'Enable subdomains per tenant?',
        );

        $tenantSubstructure = multiselect(
            label: 'Optional substructure within each tenant?',
            options: [
                'teams' => 'Teams within tenant',
                'workspaces' => 'Workspaces within tenant',
            ],
            default: [],
        );

        return [
            'database_strategy' => $databaseStrategy,
            'subdomains' => $subdomains,
            'tenant_substructure' => array_values($tenantSubstructure),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $substructure = $this->arrayToString($config['tenant_substructure']);

        $contents = <<<PHP
        <?php

        declare(strict_types=1);

        return [
            'organization' => '{$config['organization']}',
            'database_strategy' => '{$config['database_strategy']}',
            'subdomains' => {$this->boolToString($config['subdomains'])},
            'tenant_substructure' => {$substructure},
        ];
        PHP;

        $this->files->put(config_path('nubos.php'), $contents . "\n");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function copyStubs(array $config): void
    {
        $organization = $config['organization'];

        if ($organization === 'team' || $organization === 'workspace') {
            $this->copyOrganizationStubs($organization);
        }

        if ($organization === 'tenant') {
            $stubPath = $this->stubPath('tenant');

            if ($this->files->isDirectory($stubPath)) {
                $this->copyDirectory($stubPath);
            }

            foreach ($config['tenant_substructure'] as $sub) {
                $singularSub = Str::singular($sub);
                $this->copyOrganizationStubs($singularSub, $this->stubPath('tenant-sub'));
            }
        }
    }

    private function copyOrganizationStubs(string $organization, ?string $customStubPath = null): void
    {
        $stubPath = $customStubPath ?? $this->stubPath('organization');

        if (!$this->files->isDirectory($stubPath)) {
            return;
        }

        $replacements = $this->buildReplacements($organization);

        foreach ($this->files->allFiles($stubPath) as $file) {
            $relativePath = $file->getRelativePathname();

            $relativePath = Str::replaceLast('.stub', '', $relativePath);

            $relativePath = $this->replacePlaceholders($relativePath, $replacements);

            $targetPath = base_path($relativePath);
            $targetDir = dirname($targetPath);

            if (!$this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $content = $this->replacePlaceholders(
                $this->files->get($file->getPathname()),
                $replacements,
            );

            $this->files->put($targetPath, $content);
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildReplacements(string $organization): array
    {
        $singular = Str::singular($organization);
        $plural = Str::plural($organization);

        return [
            '{{Organization}}' => Str::studly($singular),
            '{{organization}}' => Str::lower($singular),
            '{{Organizations}}' => Str::studly($plural),
            '{{organizations}}' => Str::lower($plural),
        ];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replacePlaceholders(string $content, array $replacements): string
    {
        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content,
        );
    }

    private function copyDirectory(string $stubPath): void
    {
        foreach ($this->files->allFiles($stubPath) as $file) {
            $targetPath = base_path($file->getRelativePathname());
            $targetDir = dirname($targetPath);

            if (!$this->files->isDirectory($targetDir)) {
                $this->files->makeDirectory($targetDir, 0755, true);
            }

            $this->files->copy($file->getPathname(), $targetPath);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchUserModel(array $config): void
    {
        $replacements = $this->buildReplacements($config['organization']);
        $trait = $replacements['{{Organization}}'];

        $this->patchFile(app_path('Models/User.php'), $config, "use BelongsTo{$trait};", [
            ["namespace App\\Models;\n", "namespace App\\Models;\n\nuse App\\Traits\\BelongsTo{$trait};"],
            ["use Notifiable;\n", "use Notifiable;\n    use BelongsTo{$trait};\n"],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchWebRoutes(array $config): void
    {
        $path = base_path('routes/web.php');

        $isTenantWithoutSub = $config['organization'] === 'tenant'
            && empty($config['tenant_substructure']);

        if (!$this->files->exists($path) || $isTenantWithoutSub) {
            return;
        }

        $content = $this->files->get($path);

        if (preg_match(
            '/Route::middleware\(\[\'auth\',\s*\'verified\']\)->group\(function\s*\(\).*?\{(.+?)}\);/s',
            $content,
            $matches,
        )) {
            $innerRoutes = trim($matches[1]);

            $appRoutes = "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n\n{$innerRoutes}\n";
            $this->files->put(base_path('routes/app.php'), $appRoutes);

            $content = str_replace($matches[0], '', $content);
            $this->files->put($path, $content);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchBootstrapProviders(array $config): void
    {
        $replacements = $this->buildReplacements($config['organization']);
        $provider = "Nubos{$replacements['{{Organization}}']}ServiceProvider";

        $this->patchFile(base_path('bootstrap/providers.php'), $config, $provider, [
            ["return [\n", "return [\n    App\\Providers\\{$provider}::class,\n"],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchDatabaseSeeder(array $config): void
    {
        $replacements = $this->buildReplacements($config['organization']);
        $seederClass = "{$replacements['{{Organization}}']}Seeder";

        $this->patchFile(database_path('seeders/DatabaseSeeder.php'), $config, $seederClass, [
            [
                "class DatabaseSeeder extends Seeder\n{\n    public function run(): void\n    {",
                "class DatabaseSeeder extends Seeder\n{\n    public function run(): void\n    {\n        \$this->call([\n            {$seederClass}::class,\n        ]);\n",
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchInertiaMiddleware(array $config): void
    {
        if ($config['organization'] === 'tenant') {
            $this->patchFile(app_path('Http/Middleware/HandleInertiaRequests.php'), $config, "'currentTenant'", [
                ["use Illuminate\\Http\\Request;\n", "use App\\Models\\Tenant;\nuse Illuminate\\Http\\Request;\n"],
                ["'sidebarOpen'", "'currentTenant' => fn () => app()->bound('currentTenant') ? app('currentTenant') : null,\n            'sidebarOpen'"],
            ]);

            return;
        }

        $replacements = $this->buildReplacements($config['organization']);
        $model = $replacements['{{Organization}}'];
        $plural = $replacements['{{organizations}}'];

        $sharedProps = <<<PHP
            '{$plural}' => fn () => \$request->user()?->{$plural},
                    'current{$model}' => fn () => app()->bound('current{$model}') ? app('current{$model}') : null,
        PHP;

        $this->patchFile(app_path('Http/Middleware/HandleInertiaRequests.php'), $config, "'{$plural}'", [
            ["use Illuminate\\Http\\Request;\n", "use App\\Models\\{$model};\nuse Illuminate\\Http\\Request;\n"],
            ["'sidebarOpen'", "{$sharedProps}\n            'sidebarOpen'"],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws FileNotFoundException
     */
    private function patchAppSidebar(array $config): void
    {
        if ($config['organization'] === 'tenant') {
            return;
        }

        $replacements = $this->buildReplacements($config['organization']);
        $model = $replacements['{{Organization}}'];

        $this->patchFile(resource_path('js/components/AppSidebar.vue'), $config, "{$model}Switcher", [
            ["import NavUser from '@/components/NavUser.vue';", "import {$model}Switcher from '@/components/{$model}Switcher.vue';\nimport NavUser from '@/components/NavUser.vue';"],
            ["        </SidebarHeader>\n\n        <SidebarContent>", "        </SidebarHeader>\n\n        <{$model}Switcher />\n\n        <SidebarContent>"],
        ]);
    }

    /**
     * @param array<string, mixed>                              $config
     * @param array<int, array{0: string, 1: string, 2?: bool}> $replacements [search, replace, append?]
     *
     * @throws FileNotFoundException
     */
    private function patchFile(string $path, array $config, string $marker, array $replacements): void
    {
        if (!$this->files->exists($path)) {
            return;
        }

        $content = $this->files->get($path);

        if (str_contains($content, $marker)) {
            return;
        }

        foreach ($replacements as $replacement) {
            $append = $replacement[2] ?? false;

            $content = $append
                ? $content . $replacement[1]
                : str_replace($replacement[0], $replacement[1], $content);
        }

        $this->files->put($path, $content);
    }

    private function stubPath(string $stack): string
    {
        return dirname(__DIR__, 2) . '/stubs/' . $stack;
    }

    private function boolToString(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param array<int, string> $items
     */
    private function arrayToString(array $items): string
    {
        if ($items === []) {
            return '[]';
        }

        $quoted = array_map(fn (string $item): string => "'{$item}'", $items);

        return '[' . implode(', ', $quoted) . ']';
    }
}
