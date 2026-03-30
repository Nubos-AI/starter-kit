<?php

declare(strict_types=1);

namespace Nubos\Init\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

use Nubos\Init\Enums\DatabaseStrategy;
use Nubos\Init\Enums\OrganizationType;
use Nubos\Init\Enums\SubStructure;

final class NubosInitCommand extends Command
{
    private const array MIGRATION_NUMBER_REMAP = [
        '110000' => '120003',
        '110001' => '120004',
        '110002' => '120006',
        '110003' => '120007',
        '110004' => '120005',
        '110005' => '120008',
    ];
    private const array MULTI_DB_FIELDS = [
        '            $table->string(\'db_host\')->default(\'127.0.0.1\');',
        '            $table->integer(\'db_port\')->default(5432);',
        '            $table->string(\'db_database\');',
        '            $table->string(\'db_username\');',
        '            $table->text(\'db_password\');',
    ];
    private const array MULTI_DB_FACTORY_FIELDS = [
        "            'db_host' => '127.0.0.1',",
        "            'db_port' => 5432,",
        "            'db_database' => 'tenant_' . fake()->slug(2),",
        "            'db_username' => 'tenant_user',",
        "            'db_password' => fake()->password(),",
    ];
    private const array TENANT_FK_FIELDS = [
        '            $table->foreignUuid(\'tenant_id\')->constrained()->cascadeOnDelete();',
    ];

    protected $signature = 'nubos:init';
    protected $description = 'Initialize the Nubos StarterKit configuration';

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
        if ($this->isAlreadyInitialized()) {
            error('Nubos is already initialized. Delete config/nubos.php to re-initialize.');

            return self::FAILURE;
        }

        $organizationType = OrganizationType::from(select(
            label: 'Organization type?',
            options: array_column(OrganizationType::cases(), 'value'),
        ));

        return match ($organizationType) {
            OrganizationType::Team => $this->handleTeam(),
            OrganizationType::Workspace => $this->handleWorkspace(),
            OrganizationType::Tenant => $this->handleTenant(),
        };
    }

    /**
     * @throws FileNotFoundException
     */
    private function isAlreadyInitialized(): bool
    {
        $configPath = config_path('nubos.php');

        if (!$this->files->exists($configPath)) {
            return false;
        }

        $content = $this->files->get($configPath);

        return str_contains($content, 'organization_type');
    }

    /**
     * @throws FileNotFoundException
     */
    private function handleTeam(): int
    {
        return $this->installOrganization('Team', 'team', 'teams', 'Teams');
    }

    /**
     * @throws FileNotFoundException
     */
    private function handleWorkspace(): int
    {
        $hasTeams = confirm(
            label: 'Enable teams within workspaces?',
            default: false,
        );

        if (!$hasTeams) {
            return $this->installOrganization('Workspace', 'workspace', 'workspaces', 'Workspaces');
        }

        $config = [
            'organization_type' => 'workspace',
            'organization_model' => 'App\\Models\\Workspace::class',
            'has_sub_teams' => true,
            'sub_team_model' => 'App\\Models\\Team::class',
        ];

        $this->copyTeamStubs('Workspace', 'workspace', 'workspaces', 'Workspaces');
        $this->copyWorkspaceTeamsStubs();
        $this->injectTeamsRelationToWorkspaceModel();
        $this->replaceRedirectMiddlewareForWorkspaceTeams();
        $this->addTraitToUserModel('HasWorkspaces', 'App\\Traits\\Workspaces\\HasWorkspaces');
        $this->addTraitToUserModel('HasTeams', 'App\\Traits\\Teams\\HasTeams');
        $this->writeWebRoutes(
            [
                ['SetCurrentWorkspace', 'App\\Http\\Middleware\\SetCurrentWorkspace'],
                ['SetCurrentTeam', 'App\\Http\\Middleware\\SetCurrentTeam'],
            ],
            'workspaces/{workspace}/teams/{team}',
        );
        $this->writeScopeResolver([
            "\$this->request->attributes->get('current_workspace')",
            "\$this->request->attributes->get('current_team')",
        ]);
        $this->writeSeeder('workspace-teams');
        $this->writeConfig($config);

        info('Nubos initialized with Workspace + Teams organization.');

        return self::SUCCESS;
    }

    /**
     * @throws FileNotFoundException
     */
    private function installOrganization(
        string $model,
        string $modelSnake,
        string $modelsPlural,
        string $modelsPascalPlural,
    ): int {
        $config = [
            'organization_type' => $modelSnake,
            'organization_model' => "App\\Models\\{$model}::class",
            'has_sub_teams' => false,
        ];

        $this->copyTeamStubs($model, $modelSnake, $modelsPlural, $modelsPascalPlural);
        $this->compactStandaloneMigrationNumbers($modelSnake);
        $this->addTraitToUserModel("Has{$modelsPascalPlural}", "App\\Traits\\{$modelsPascalPlural}\\Has{$modelsPascalPlural}");
        $this->writeWebRoutes(
            [["SetCurrent{$model}", "App\\Http\\Middleware\\SetCurrent{$model}"]],
            "{$modelsPlural}/{{$modelSnake}}",
        );
        $this->writeScopeResolver([
            "\$this->request->attributes->get('current_{$modelSnake}')",
        ]);
        $this->writeSeeder($modelSnake);
        $this->writeConfig($config);

        info("Nubos initialized with {$model} organization.");

        return self::SUCCESS;
    }

    /**
     * @throws FileNotFoundException
     */
    private function handleTenant(): int
    {
        $databaseStrategy = DatabaseStrategy::from(select(
            label: 'Database strategy?',
            options: array_column(DatabaseStrategy::cases(), 'value'),
        ));

        $subStructure = SubStructure::from(select(
            label: 'Sub-structure within tenant?',
            options: array_column(SubStructure::cases(), 'value'),
        ));

        $isMultiDb = $databaseStrategy === DatabaseStrategy::MultiDatabase;
        $subOrganization = $this->resolveSubOrganization($subStructure);

        $config = [
            'organization_type' => 'tenant',
            'organization_model' => 'App\\Models\\Tenant::class',
            'database_strategy' => $isMultiDb ? 'multi' : 'single',
            'sub_organization' => $subOrganization,
            ...$this->resolveSubTeamConfig($subStructure),
        ];

        $this->copyTenantStubs($isMultiDb);
        $this->copyTenantSubOrgStubs($subStructure);
        $this->addTraitToUserModel('BelongsToTenant', 'App\\Traits\\Tenants\\BelongsToTenant');

        if (in_array($subStructure, [SubStructure::Workspaces, SubStructure::WorkspacesAndTeams], true)) {
            $this->addTraitToUserModel('HasWorkspaces', 'App\\Traits\\Workspaces\\HasWorkspaces');
        }

        if (in_array($subStructure, [SubStructure::Teams, SubStructure::WorkspacesAndTeams], true)) {
            $this->addTraitToUserModel('HasTeams', 'App\\Traits\\Teams\\HasTeams');
        }
        $this->writeWebRoutes(...$this->resolveWebRouteParams($subStructure));
        $this->writeScopeResolver($this->resolveScopeResolverScopes($subStructure));
        $this->writeSeeder('tenant', $subOrganization);
        $this->writeConfig($config);

        info('Nubos initialized with Tenant organization.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<array{0: string, 1: string}>, 1: string|null}
     */
    private function resolveWebRouteParams(SubStructure $subStructure): array
    {
        $middleware = [['TenantIdentification', 'App\\Http\\Middleware\\TenantIdentification']];
        $prefix = null;

        if (in_array($subStructure, [SubStructure::Workspaces, SubStructure::WorkspacesAndTeams], true)) {
            $middleware[] = ['SetCurrentWorkspace', 'App\\Http\\Middleware\\SetCurrentWorkspace'];
            $prefix = 'workspaces/{workspace}';
        }

        if (in_array($subStructure, [SubStructure::Teams, SubStructure::WorkspacesAndTeams], true)) {
            $middleware[] = ['SetCurrentTeam', 'App\\Http\\Middleware\\SetCurrentTeam'];
            $prefix = $prefix !== null ? $prefix . '/teams/{team}' : 'teams/{team}';
        }

        return [$middleware, $prefix];
    }

    private function resolveSubOrganization(SubStructure $subStructure): ?string
    {
        return match ($subStructure) {
            SubStructure::None => null,
            SubStructure::Teams => 'team',
            SubStructure::Workspaces => 'workspace',
            SubStructure::WorkspacesAndTeams => 'workspace-teams',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSubTeamConfig(SubStructure $subStructure): array
    {
        return match ($subStructure) {
            SubStructure::Teams => [
                'has_sub_teams' => true,
                'sub_team_model' => 'App\\Models\\Team::class',
            ],
            SubStructure::Workspaces => [
                'has_sub_teams' => true,
                'sub_organization_model' => 'App\\Models\\Workspace::class',
            ],
            SubStructure::WorkspacesAndTeams => [
                'has_sub_teams' => true,
                'sub_organization_model' => 'App\\Models\\Workspace::class',
                'sub_team_model' => 'App\\Models\\Team::class',
            ],
            SubStructure::None => [
                'has_sub_teams' => false,
            ],
        };
    }

    private function copyTenantStubs(bool $isMultiDb): void
    {
        $stubPath = $this->stubPath('tenant');

        $this->copyDirectory($stubPath, [], function (string $content) use ($isMultiDb): string {
            if ($isMultiDb) {
                $content = $this->injectFields($content, self::MULTI_DB_FIELDS);
                $content = $this->injectFields($content, self::MULTI_DB_FACTORY_FIELDS, 'inject-factory-fields');
                $content = $this->injectTrait($content, 'HasTenantDatabase', 'App\\Traits\\Tenants\\HasTenantDatabase');
                $content = $this->injectMultiDbModelFields($content);
                $content = $this->keepMultiDbCode($content);
            } else {
                $content = $this->removeMultiDbBlocks($content);
            }

            return $content;
        });

        if (!$isMultiDb) {
            $this->files->delete(app_path('Traits/Tenants/HasTenantDatabase.php'));
            $this->files->delete(app_path('Traits/Tenants/TenantAware.php'));
            $this->files->delete(app_path('Actions/Tenants/ConfigureTenantDatabaseAction.php'));
            $this->files->delete(app_path('Queue/Middleware/TenantAwareJob.php'));
            $this->files->delete(base_path('tests/Feature/TenantMultiDbTest.php'));
        }
    }

    /**
     * @throws FileNotFoundException
     */
    private function copyTenantSubOrgStubs(SubStructure $subStructure): void
    {
        if ($subStructure === SubStructure::None) {
            return;
        }

        if ($subStructure === SubStructure::Teams) {
            $this->copyTeamStubs('Team', 'team', 'teams', 'Teams', true);
            $this->injectTenantTestSetup('Team', 'team', 'teams');
        }

        if ($subStructure === SubStructure::Workspaces) {
            $this->copyTeamStubs('Workspace', 'workspace', 'workspaces', 'Workspaces', true);
            $this->injectTenantTestSetup('Workspace', 'workspace', 'workspaces');
        }

        if ($subStructure === SubStructure::WorkspacesAndTeams) {
            $this->copyTeamStubs('Workspace', 'workspace', 'workspaces', 'Workspaces', true);
            $this->copyWorkspaceTeamsStubs(true);
            $this->injectTeamsRelationToWorkspaceModel();
            $this->replaceRedirectMiddlewareForWorkspaceTeams();
            $this->injectTenantTestSetup('Workspace', 'workspace', 'workspaces');
            $this->injectTenantTestSetup('Team', 'team', 'teams');
        }
    }

    private function copyTeamStubs(
        string $model,
        string $modelSnake,
        string $modelsPlural,
        string $modelsPascalPlural,
        bool $underTenant = false,
    ): void {
        $stubPath = $this->stubPath('team');

        $replacements = [
            '{{Model}}' => $model,
            '{{model}}' => $modelSnake,
            '{{models}}' => $modelsPlural,
            '{{Models}}' => $modelsPascalPlural,
        ];

        $this->copyDirectory($stubPath, $replacements, function (string $content) use ($underTenant): string {
            if ($underTenant) {
                $content = $this->injectFields($content, self::TENANT_FK_FIELDS);
                $content = $this->injectTrait($content, 'TenantScope', 'App\\Traits\\Tenants\\TenantScope');
                $content = $this->injectTenantMiddleware($content);
                $content = $this->scopeSlugUniqueness($content);
            }

            return $content;
        }, $underTenant);
    }

    private function copyWorkspaceTeamsStubs(bool $underTenant = false): void
    {
        $stubPath = $this->stubPath('workspace-teams');

        $this->copyDirectory($stubPath, [], function (string $content) use ($underTenant): string {
            if ($underTenant) {
                $content = $this->injectFields($content, self::TENANT_FK_FIELDS);
                $content = $this->injectTrait($content, 'TenantScope', 'App\\Traits\\Tenants\\TenantScope');
                $content = $this->injectTenantMiddleware($content);
            }

            return $content;
        }, $underTenant);
    }

    /**
     * @param array<string, string> $replacements
     */
    private function copyDirectory(
        string $stubPath,
        array $replacements,
        ?Closure $contentTransformer = null,
        bool $remapMigrationNumbers = false,
    ): void {
        if (!$this->files->isDirectory($stubPath)) {
            return;
        }

        $files = $this->files->allFiles($stubPath);

        foreach ($files as $file) {
            $content = $this->applyReplacements($file->getContents(), $replacements);
            $relativePath = str_replace(
                '.stub.php',
                '.php',
                $this->applyReplacements($file->getRelativePathname(), $replacements),
            );

            if ($contentTransformer !== null) {
                $content = $contentTransformer($content);
            }

            $content = $this->removeMarkers($content);

            if ($remapMigrationNumbers) {
                $relativePath = $this->remapMigrationNumber($relativePath);
            }

            $targetPath = $this->resolveTargetPath($relativePath);

            $this->files->ensureDirectoryExists(dirname($targetPath));
            $this->files->put($targetPath, $content);
        }
    }

    /**
     * @param array<string, string> $replacements
     */
    private function applyReplacements(string $subject, array $replacements): string
    {
        if ($replacements === []) {
            return $subject;
        }

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $subject,
        );
    }

    /**
     * @param list<string> $fields
     */
    private function injectFields(string $content, array $fields, string $marker = 'inject-fields'): string
    {
        return str_replace(
            "// @nubos:{$marker}",
            implode("\n", $fields) . "\n            // @nubos:{$marker}",
            $content,
        );
    }

    private function injectTrait(string $content, string $traitShortName, string $traitFqcn): string
    {
        $content = str_replace(
            '// @nubos:inject-traits',
            "use {$traitShortName};\n    // @nubos:inject-traits",
            $content,
        );

        return str_replace(
            '// @nubos:inject-imports',
            "use {$traitFqcn};\n// @nubos:inject-imports",
            $content,
        );
    }

    private function removeMarkers(string $content): string
    {
        $markers = [
            '// @nubos:inject-fields',
            '// @nubos:inject-factory-fields',
            '// @nubos:inject-traits',
            '// @nubos:inject-imports',
        ];

        foreach ($markers as $marker) {
            $content = str_replace($marker, '', $content);
        }

        return preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
    }

    private function removeMultiDbBlocks(string $content): string
    {
        return (string) preg_replace(
            '/\s*\/\/ @nubos:multi-db-start\n.*?\/\/ @nubos:multi-db-end\n?/s',
            "\n",
            $content,
        );
    }

    private function keepMultiDbCode(string $content): string
    {
        return (string) preg_replace('/^[ \t]*\/\/ @nubos:multi-db-(start|end)\n?/m', '', $content);
    }

    private function remapMigrationNumber(string $relativePath): string
    {
        foreach (self::MIGRATION_NUMBER_REMAP as $from => $to) {
            if (str_contains($relativePath, (string) $from)) {
                $relativePath = str_replace((string) $from, $to, $relativePath);
                break;
            }
        }

        return $relativePath;
    }

    private function resolveTargetPath(string $relativePath): string
    {
        $mappings = [
            'factories/' => base_path('database/factories/'),
            'migrations/' => base_path('database/migrations/'),
            'models/' => app_path('Models/'),
            'traits/' => app_path('Traits/'),
            'middleware/' => app_path('Http/Middleware/'),
            'queue/' => app_path('Queue/Middleware/'),
            'actions/' => app_path('Actions/'),
            'events/' => app_path('Events/'),
            'providers/' => app_path('Providers/'),
            'services/' => app_path('Services/'),
            'seeders/' => base_path('database/seeders/'),
            'tests/' => base_path('tests/Feature/'),
            'routes/' => base_path('routes/'),
        ];

        foreach ($mappings as $stubDir => $targetDir) {
            if (str_starts_with($relativePath, $stubDir)) {
                $fileRelative = substr($relativePath, strlen($stubDir));

                return $targetDir . $fileRelative;
            }
        }

        return base_path($relativePath);
    }

    /**
     * @param list<array{0: string, 1: string}> $extraMiddleware
     */
    private function writeWebRoutes(array $extraMiddleware, ?string $prefix = null): void
    {
        $imports = [
            'use Illuminate\Support\Facades\Route;',
            'use Laravel\Fortify\Features;',
        ];
        $middlewareEntries = ["'auth'", "'verified'"];

        foreach ($extraMiddleware as [$className, $fqcn]) {
            $imports[] = "use {$fqcn};";
            $middlewareEntries[] = "{$className}::class";
        }

        sort($imports);

        $importsBlock = implode("\n", $imports);
        $middlewareStr = implode(', ', $middlewareEntries);
        $prefixLine = $prefix !== null ? "\n    ->prefix('{$prefix}')" : '';
        $file = <<<PHP
        <?php

        declare(strict_types=1);

        {$importsBlock}

        Route::inertia('/', 'Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
        ])->name('home');

        Route::middleware([{$middlewareStr}])
            {$prefixLine}
            ->group(function () {
                Route::inertia('dashboard', 'Dashboard')->name('dashboard');
            }
        );

        require __DIR__ . '/settings.php';
        PHP;

        $this->files->put(base_path('routes/web.php'), $file);
    }

    private function writeSeeder(string $type, ?string $subOrganization = null): void
    {
        $seederPath = base_path('database/seeders/NubosSeeder.php');

        $this->files->ensureDirectoryExists(dirname($seederPath));
        $this->files->put($seederPath, $this->generateSeederContent($type, $subOrganization));

        $orgRolesSeederPath = base_path('database/seeders/OrgRolesAndPermissionsSeeder.php');
        $this->files->put($orgRolesSeederPath, $this->generateOrgRolesSeeder($type, $subOrganization));
    }

    private function generateOrgRolesSeeder(string $type, ?string $subOrganization): string
    {
        $scope = match ($type) {
            'team' => 'team',
            'workspace', 'workspace-teams' => 'workspace',
            'tenant' => 'tenant',
            default => 'organization',
        };

        $hasSubTeams = in_array($subOrganization, ['team', 'workspace-teams'], true)
            || $type === 'workspace-teams';

        $orgRoles = <<<PHP
                    ['name' => 'owner', 'scope' => '{$scope}', 'is_system' => true],
                    ['name' => 'admin', 'scope' => '{$scope}', 'is_system' => true],
                    ['name' => 'member', 'scope' => '{$scope}', 'is_system' => true],
        PHP;

        $orgPermissions = <<<PHP
                    ['name' => 'org.settings.view', 'group' => 'settings', 'scope' => '{$scope}'],
                    ['name' => 'org.settings.update', 'group' => 'settings', 'scope' => '{$scope}'],
                    ['name' => 'org.delete', 'group' => 'settings', 'scope' => '{$scope}'],
                    ['name' => 'members.view', 'group' => 'members', 'scope' => '{$scope}'],
                    ['name' => 'members.invite', 'group' => 'members', 'scope' => '{$scope}'],
                    ['name' => 'members.remove', 'group' => 'members', 'scope' => '{$scope}'],
                    ['name' => 'members.change-role', 'group' => 'members', 'scope' => '{$scope}'],
        PHP;

        $orgMapping = <<<'PHP'
                    'admin' => [
                        'org.settings.view',
                        'members.view',
                        'members.invite',
                        'members.remove',
                        'members.change-role',
                    ],
                    'member' => [
                        'members.view',
                    ],
        PHP;

        $teamRoles = '';
        $teamPermissions = '';
        $teamMapping = '';

        if ($hasSubTeams) {
            $orgPermissions .= <<<PHP

                        ['name' => 'teams.view', 'group' => 'teams', 'scope' => '{$scope}'],
                        ['name' => 'teams.create', 'group' => 'teams', 'scope' => '{$scope}'],
                        ['name' => 'teams.update', 'group' => 'teams', 'scope' => '{$scope}'],
                        ['name' => 'teams.delete', 'group' => 'teams', 'scope' => '{$scope}'],
                        ['name' => 'teams.members.manage', 'group' => 'teams', 'scope' => '{$scope}'],
            PHP;

            $orgMapping = <<<'PHP'
                    'admin' => [
                        'org.settings.view',
                        'members.view',
                        'members.invite',
                        'members.remove',
                        'members.change-role',
                        'teams.view',
                        'teams.create',
                        'teams.update',
                        'teams.delete',
                        'teams.members.manage',
                    ],
                    'member' => [
                        'members.view',
                        'teams.view',
                    ],
            PHP;

            $teamRoles = <<<'PHP'

                    ['name' => 'team:lead', 'scope' => 'team', 'is_system' => true],
                    ['name' => 'team:member', 'scope' => 'team', 'is_system' => true],
            PHP;

            $teamPermissions = <<<'PHP'

                    ['name' => 'team.settings.view', 'group' => 'team-settings', 'scope' => 'team'],
                    ['name' => 'team.settings.update', 'group' => 'team-settings', 'scope' => 'team'],
                    ['name' => 'team.members.view', 'group' => 'team-members', 'scope' => 'team'],
                    ['name' => 'team.members.add', 'group' => 'team-members', 'scope' => 'team'],
                    ['name' => 'team.members.remove', 'group' => 'team-members', 'scope' => 'team'],
            PHP;

            $teamMapping = <<<'PHP'

                    'team:lead' => [
                        'team.settings.view',
                        'team.settings.update',
                        'team.members.view',
                        'team.members.add',
                        'team.members.remove',
                    ],
                    'team:member' => [
                        'team.members.view',
                    ],
            PHP;
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\Seeders;

        use App\Models\Permission;
        use App\Models\Role;
        use Illuminate\Database\Seeder;

        class OrgRolesAndPermissionsSeeder extends Seeder
        {
            public function run(): void
            {
                \$this->seedRoles();
                \$this->seedPermissions();
                \$this->assignPermissions();
            }

            private function seedRoles(): void
            {
                \$roles = [
        {$orgRoles}{$teamRoles}
                ];

                foreach (\$roles as \$role) {
                    Role::query()->firstOrCreate(
                        ['name' => \$role['name'], 'scope' => \$role['scope']],
                        \$role,
                    );
                }
            }

            private function seedPermissions(): void
            {
                \$permissions = [
        {$orgPermissions}{$teamPermissions}
                ];

                foreach (\$permissions as \$permission) {
                    Permission::query()->firstOrCreate(
                        ['name' => \$permission['name'], 'scope' => \$permission['scope']],
                        [...\$permission, 'is_system' => true],
                    );
                }
            }

            private function assignPermissions(): void
            {
                \$mapping = [
        {$orgMapping}{$teamMapping}
                ];

                foreach (\$mapping as \$roleName => \$permissionNames) {
                    \$role = Role::query()->where('name', \$roleName)->first();

                    if (\$role === null) {
                        continue;
                    }

                    \$permissionIds = Permission::query()
                        ->whereIn('name', \$permissionNames)
                        ->pluck('id');

                    \$role->permissions()->syncWithoutDetaching(\$permissionIds);
                }
            }
        }

        PHP;
    }

    private function generateSeederContent(string $type, ?string $subOrganization): string
    {
        return match ($type) {
            'team' => $this->seederForOrg('Team', 'team', 'Teams'),
            'workspace' => $this->seederForOrg('Workspace', 'workspace', 'Workspaces'),
            'workspace-teams' => $this->seederForWorkspaceTeams(),
            'tenant' => $this->seederForTenant($subOrganization),
            default => '',
        };
    }

    private function seederForOrg(string $model, string $modelSnake, string $modelPlural): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\Seeders;

        use App\Actions\\{$modelPlural}\Add{$model}MemberAction;
        use App\Actions\\{$modelPlural}\Create{$model}Action;
        use App\Models\Role;
        use App\Models\User;
        use Illuminate\Database\Seeder;
        use Illuminate\Support\Facades\Hash;

        class NubosSeeder extends Seeder
        {
            public function run(): void
            {
                \$this->call(OrgRolesAndPermissionsSeeder::class);

                \$user = User::query()->create([
                    'name' => 'Demo User',
                    'email' => 'demo@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                \$createAction = new Create{$model}Action();

                \$createAction->execute(\$user, [
                    'name' => 'Personal',
                    'personal_{$modelSnake}' => true,
                ]);

                \${$modelSnake} = \$createAction->execute(\$user, [
                    'name' => 'Acme Corp',
                ]);

                \$secondUser = User::query()->create([
                    'name' => 'Second User',
                    'email' => 'second@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                \$addMemberAction = new Add{$model}MemberAction();
                \$addMemberAction->execute(\${$modelSnake}, \$secondUser, 'member');

                \$ownerRole = Role::query()->where('name', 'owner')->where('scope', '{$modelSnake}')->firstOrFail();
                \$memberRole = Role::query()->where('name', 'member')->where('scope', '{$modelSnake}')->firstOrFail();

                \$user->assignRole(\$ownerRole, \${$modelSnake});
                \$secondUser->assignRole(\$memberRole, \${$modelSnake});
            }
        }

        PHP;
    }

    private function seederForWorkspaceTeams(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Database\Seeders;

        use App\Actions\Teams\AddTeamMemberAction;
        use App\Actions\Teams\CreateTeamAction;
        use App\Actions\Workspaces\AddWorkspaceMemberAction;
        use App\Actions\Workspaces\CreateWorkspaceAction;
        use App\Models\Role;
        use App\Models\User;
        use Illuminate\Database\Seeder;
        use Illuminate\Support\Facades\Hash;

        class NubosSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(OrgRolesAndPermissionsSeeder::class);

                $user = User::query()->create([
                    'name' => 'Demo User',
                    'email' => 'demo@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                $createWorkspaceAction = new CreateWorkspaceAction();

                $createWorkspaceAction->execute($user, [
                    'name' => 'Personal',
                    'personal_workspace' => true,
                ]);

                $workspace = $createWorkspaceAction->execute($user, [
                    'name' => 'Acme Corp',
                ]);

                $createTeamAction = new CreateTeamAction();

                $team = $createTeamAction->execute($user, $workspace, [
                    'name' => 'Engineering',
                ]);

                $secondUser = User::query()->create([
                    'name' => 'Second User',
                    'email' => 'second@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                $addWorkspaceMemberAction = new AddWorkspaceMemberAction();
                $addWorkspaceMemberAction->execute($workspace, $secondUser, 'member');

                $addTeamMemberAction = new AddTeamMemberAction();
                $addTeamMemberAction->execute($team, $secondUser, 'member');

                $ownerRole = Role::query()->where('name', 'owner')->where('scope', 'workspace')->firstOrFail();
                $memberRole = Role::query()->where('name', 'member')->where('scope', 'workspace')->firstOrFail();
                $teamLeadRole = Role::query()->where('name', 'team:lead')->where('scope', 'team')->firstOrFail();
                $teamMemberRole = Role::query()->where('name', 'team:member')->where('scope', 'team')->firstOrFail();

                $user->assignRole($ownerRole, $workspace);
                $user->assignRole($teamLeadRole, $team);
                $secondUser->assignRole($memberRole, $workspace);
                $secondUser->assignRole($teamMemberRole, $team);
            }
        }

        PHP;
    }

    private function seederForTenant(?string $subOrganization): string
    {
        $subOrgCode = $this->generateSubOrgSeederCode($subOrganization);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\Seeders;

        use App\Actions\Tenants\AddTenantMemberAction;
        use App\Actions\Tenants\CreateTenantAction;
        use App\Models\Role;
        use App\Models\User;
        use Illuminate\Database\Seeder;
        use Illuminate\Support\Facades\Hash;

        class NubosSeeder extends Seeder
        {
            public function run(): void
            {
                \$this->call(OrgRolesAndPermissionsSeeder::class);

                \$user = User::query()->create([
                    'name' => 'Demo User',
                    'email' => 'demo@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                \$createAction = app(CreateTenantAction::class);

                \$acme = \$createAction->execute(\$user, [
                    'name' => 'Acme Corp',
                    'slug' => 'acme',
                ]);

                \$createAction->execute(\$user, [
                    'name' => 'Globex Corp',
                    'slug' => 'globex',
                ]);

                \$secondUser = User::query()->create([
                    'name' => 'Second User',
                    'email' => 'second@nubos.dev',
                    'password' => Hash::make('password'),
                ]);

                \$addMemberAction = app(AddTenantMemberAction::class);
                \$addMemberAction->execute(\$acme, \$secondUser, 'member');

                \$ownerRole = Role::query()->where('name', 'owner')->where('scope', 'tenant')->firstOrFail();
                \$memberRole = Role::query()->where('name', 'member')->where('scope', 'tenant')->firstOrFail();

                \$user->assignRole(\$ownerRole, \$acme);
                \$secondUser->assignRole(\$memberRole, \$acme);
        {$subOrgCode}
            }
        }

        PHP;
    }

    private function generateSubOrgSeederCode(?string $subOrganization): string
    {
        if ($subOrganization === null) {
            return '';
        }

        return match ($subOrganization) {
            'team' => $this->subOrgSeederCode('Team', 'team', 'Teams'),
            'workspace' => $this->subOrgSeederCode('Workspace', 'workspace', 'Workspaces'),
            'workspace-teams' => $this->subOrgSeederCode('Workspace', 'workspace', 'Workspaces')
                . $this->workspaceTeamsSubOrgSeederCode(),
            default => '',
        };
    }

    private function subOrgSeederCode(string $model, string $modelSnake, string $modelsPascalPlural): string
    {
        return <<<PHP

                app()->instance('current_tenant', \$acme);

                \$create{$model}Action = app(\\App\\Actions\\{$modelsPascalPlural}\\Create{$model}Action::class);
                \${$modelSnake} = \$create{$model}Action->execute(\$user, ['name' => 'Engineering']);

                \$add{$model}MemberAction = app(\\App\\Actions\\{$modelsPascalPlural}\\Add{$model}MemberAction::class);
                \$add{$model}MemberAction->execute(\${$modelSnake}, \$secondUser, 'member');

        PHP;
    }

    private function workspaceTeamsSubOrgSeederCode(): string
    {
        return <<<'PHP'

                $createTeamAction = app(\App\Actions\Teams\CreateTeamAction::class);
                $createTeamAction->execute($user, $workspace, [
                    'name' => 'Backend Team',
                ]);

        PHP;
    }

    /**
     * @throws FileNotFoundException
     */
    private function addTraitToUserModel(string $traitShortName, string $traitFqcn): void
    {
        $userModelPath = app_path('Models/User.php');

        if (!$this->files->exists($userModelPath)) {
            return;
        }

        $content = $this->files->get($userModelPath);

        if (str_contains($content, "use {$traitShortName};")) {
            return;
        }

        $useStatement = "use {$traitFqcn};";

        if (!str_contains($content, $useStatement)) {
            $content = preg_replace(
                '/(namespace App\\\\Models;\s*\n)/',
                "$1\n{$useStatement}\n",
                $content,
            );
        }

        $traitUse = "    use {$traitShortName};";

        if (preg_match('/^(\s*use\s+\w+;)\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            if (preg_match_all('/^ {4}use \w+;\s*$/m', $content, $traitMatches, PREG_OFFSET_CAPTURE)) {
                $lastMatch = end($traitMatches[0]);
                $lastTraitPos = $lastMatch[1] + strlen($lastMatch[0]);
                $content = substr($content, 0, $lastTraitPos) . "\n{$traitUse}" . substr($content, $lastTraitPos);
            }
        } else {
            $content = preg_replace(
                '/(class User extends[^{]*\{)/',
                "$1\n{$traitUse}",
                $content,
            );
        }

        $this->files->put($userModelPath, $content);

        info("User model updated: added {$traitShortName} trait to app/Models/User.php");
    }

    /**
     * @return list<string>
     */
    private function resolveScopeResolverScopes(SubStructure $subStructure): array
    {
        $scopes = ["app()->bound('current_tenant') ? app('current_tenant') : null"];

        if (in_array($subStructure, [SubStructure::Workspaces, SubStructure::WorkspacesAndTeams], true)) {
            $scopes[] = "\$this->request->attributes->get('current_workspace')";
        }

        if (in_array($subStructure, [SubStructure::Teams, SubStructure::WorkspacesAndTeams], true)) {
            $scopes[] = "\$this->request->attributes->get('current_team')";
        }

        return $scopes;
    }

    /**
     * @param list<string> $scopes
     */
    private function writeScopeResolver(array $scopes): void
    {
        $scopeLines = array_map(
            fn (string $scope): string => "            {$scope},",
            $scopes,
        );
        $scopeCode = implode("\n", $scopeLines);

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Services\Authorization;

        use App\Contracts\Authorization\ScopeResolverInterface;
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Http\Request;

        class ScopeResolver implements ScopeResolverInterface
        {
            public function __construct(
                private readonly Request \$request,
            ) {}

            /**
             * @return list<Model>
             */
            public function resolve(): array
            {
                return array_values(array_filter([
        {$scopeCode}
                ]));
            }
        }

        PHP;

        $targetPath = app_path('Services/Authorization/ScopeResolver.php');

        $this->files->ensureDirectoryExists(dirname($targetPath));
        $this->files->put($targetPath, $content);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $this->arrayToPhp($config, 1) . ";\n";

        $this->files->ensureDirectoryExists(config_path());
        $this->files->put(config_path('nubos.php'), $content);
    }

    /**
     * @param array<string, mixed> $array
     */
    private function arrayToPhp(array $array, int $indent = 0): string
    {
        $pad = str_repeat('    ', $indent);
        $innerPad = str_repeat('    ', $indent + 1);
        $lines = ['['];

        foreach ($array as $key => $value) {
            $exportedKey = var_export($key, true);

            if (is_array($value)) {
                $exportedValue = $this->arrayToPhp($value, $indent + 1);
            } elseif (is_null($value)) {
                $exportedValue = 'null';
            } elseif (is_bool($value)) {
                $exportedValue = $value ? 'true' : 'false';
            } elseif (is_string($value) && str_ends_with($value, '::class')) {
                $exportedValue = '\\' . $value;
            } else {
                $exportedValue = var_export($value, true);
            }

            $lines[] = "{$innerPad}{$exportedKey} => {$exportedValue},";
        }

        $lines[] = "{$pad}]";

        return implode("\n", $lines);
    }

    private function injectMultiDbModelFields(string $content): string
    {
        $content = str_replace(
            "        'name',\n    ];",
            "        'name',\n        'db_host',\n        'db_port',\n        'db_database',\n        'db_username',\n        'db_password',\n    ];\n\n    protected \$hidden = [\n        'db_password',\n    ];\n\n    protected function casts(): array\n    {\n        return [\n            'db_password' => 'encrypted',\n        ];\n    }",
            $content,
        );

        return $content;
    }

    private function injectTenantMiddleware(string $content): string
    {
        if (!str_contains($content, 'Route::middleware(')) {
            return $content;
        }

        $content = str_replace(
            'use Illuminate\\Support\\Facades\\Route;',
            "use App\\Http\\Middleware\\TenantIdentification;\nuse Illuminate\\Support\\Facades\\Route;",
            $content,
        );

        $content = str_replace(
            "'auth', ",
            "'auth', TenantIdentification::class, ",
            $content,
        );

        return $content;
    }

    private function scopeSlugUniqueness(string $content): string
    {
        if (!str_contains($content, "'slug')->unique()")) {
            return $content;
        }

        $content = str_replace(
            "\$table->string('slug')->unique();",
            "\$table->string('slug');",
            $content,
        );

        return str_replace(
            '            $table->softDeletes();',
            "            \$table->softDeletes();\n\n            \$table->unique(['tenant_id', 'slug']);",
            $content,
        );
    }

    private function compactStandaloneMigrationNumbers(string $modelSnake): void
    {
        $migrationPath = base_path('database/migrations');
        $oldSuffix = "110004_add_current_{$modelSnake}_id_to_users_table.php";
        $newSuffix = "110002_add_current_{$modelSnake}_id_to_users_table.php";

        foreach ($this->files->files($migrationPath) as $file) {
            if (str_contains($file->getFilename(), $oldSuffix)) {
                $newPath = $migrationPath . '/' . str_replace($oldSuffix, $newSuffix, $file->getFilename());
                $this->files->move($file->getPathname(), $newPath);
                break;
            }
        }
    }

    private function injectTeamsRelationToWorkspaceModel(): void
    {
        $modelPath = app_path('Models/Workspace.php');

        if (!$this->files->exists($modelPath)) {
            return;
        }

        $content = $this->files->get($modelPath);

        if (str_contains($content, 'function teams()')) {
            return;
        }

        if (!str_contains($content, 'use Illuminate\Database\Eloquent\Relations\HasMany;')) {
            $content = str_replace(
                'use Illuminate\Database\Eloquent\Relations\BelongsToMany;',
                "use Illuminate\Database\Eloquent\Relations\BelongsToMany;\nuse Illuminate\Database\Eloquent\Relations\HasMany;",
                $content,
            );
        }

        $relation = <<<'PHP'

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
PHP;

        $content = preg_replace(
            '/(public function users\(\):.*?\n {4}})/s',
            "$1\n" . $relation,
            $content,
        );

        $this->files->put($modelPath, $content);
    }

    /**
     * @throws FileNotFoundException
     */
    private function replaceRedirectMiddlewareForWorkspaceTeams(): void
    {
        $providerPath = app_path('Providers/NubosOrganizationServiceProvider.php');

        if (!$this->files->exists($providerPath)) {
            return;
        }

        $content = $this->files->get($providerPath);

        $content = str_replace(
            'use App\\Http\\Middleware\\RedirectToCurrentWorkspace;',
            'use App\\Http\\Middleware\\RedirectToCurrentOrg;',
            $content,
        );

        $content = str_replace(
            "'redirect-to-current-workspace' => RedirectToCurrentWorkspace::class,",
            "'redirect-to-current-org' => RedirectToCurrentOrg::class,",
            $content,
        );

        $this->files->put($providerPath, $content);

        $this->files->delete(app_path('Http/Middleware/RedirectToCurrentWorkspace.php'));
    }

    /**
     * @throws FileNotFoundException
     */
    private function injectTenantTestSetup(string $model, string $modelSnake, string $modelsPlural): void
    {
        $testFiles = [
            base_path("tests/Feature/{$model}ActionTest.php"),
            base_path("tests/Feature/{$model}ModelTest.php"),
            base_path("tests/Feature/{$model}MiddlewareTest.php"),
        ];

        $tenantSetup = <<<'SETUP'

beforeEach(function () {
    $this->tenant = \App\Models\Tenant::factory()->create();
    $this->domain = \App\Models\Domain::factory()->create([
        'tenant_id' => $this->tenant->id,
        'domain' => 'test-tenant',
        'is_primary' => true,
    ]);
    app()->instance('current_tenant', $this->tenant);
});

SETUP;

        foreach ($testFiles as $testFile) {
            if (!$this->files->exists($testFile)) {
                continue;
            }

            $content = $this->files->get($testFile);

            if (str_contains($content, 'current_tenant')) {
                continue;
            }

            $content = preg_replace(
                '/(declare\(strict_types:\s*1\);\s*\n)/',
                "$1{$tenantSetup}",
                $content,
            );

            if (str_contains($testFile, 'MiddlewareTest')) {
                $content = str_replace(
                    "->get('/{$modelsPlural}/",
                    "->get('http://test-tenant.localhost/{$modelsPlural}/",
                    $content,
                );
            }

            $this->files->put($testFile, $content);
        }
    }

    private function stubPath(string $directory): string
    {
        return dirname(__DIR__) . '/Stubs/' . $directory;
    }
}
