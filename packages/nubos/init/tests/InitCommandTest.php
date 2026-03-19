<?php

declare(strict_types=1);

beforeEach(function (): void {
    cleanupGeneratedFiles();
});

afterEach(function (): void {
    cleanupGeneratedFiles();
});

function cleanupGeneratedFiles(): void
{
    $paths = [
        config_path('nubos.php'),
        app_path('Models/Team.php'),
        app_path('Models/Workspace.php'),
        app_path('Models/Tenant.php'),
        app_path('Traits/BelongsToTeam.php'),
        app_path('Traits/BelongsToWorkspace.php'),
        app_path('Traits/BelongsToTenant.php'),
        app_path('Traits/TenantScope.php'),
        app_path('Traits/UsesTenantConnection.php'),
        app_path('Traits/ConfiguresTenantDatabase.php'),
        app_path('Jobs/Concerns/TenantAware.php'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Middleware/SetCurrentWorkspace.php'),
        app_path('Http/Middleware/IdentifyTenant.php'),
        app_path('Policies/TeamPolicy.php'),
        app_path('Policies/WorkspacePolicy.php'),
        app_path('Policies/TenantPolicy.php'),
        app_path('Providers/NubosTeamServiceProvider.php'),
        app_path('Providers/NubosWorkspaceServiceProvider.php'),
        app_path('Providers/NubosTenantServiceProvider.php'),
        app_path('Services/TenantDatabaseManager.php'),
        app_path('Console/Commands/TenantMigrateCommand.php'),
        base_path('routes/app.php'),
        base_path('routes/team.php'),
        base_path('routes/workspace.php'),
        base_path('routes/tenant.php'),
        resource_path('js/components/TeamSwitcher.vue'),
        resource_path('js/components/WorkspaceSwitcher.vue'),
        database_path('factories/TeamFactory.php'),
        database_path('factories/WorkspaceFactory.php'),
        database_path('factories/TenantFactory.php'),
        database_path('seeders/TeamSeeder.php'),
        database_path('seeders/WorkspaceSeeder.php'),
        database_path('seeders/TenantSeeder.php'),
        base_path('tests/Feature/TenantScopeTest.php'),
        base_path('tests/Feature/IdentifyTenantTest.php'),
        base_path('tests/Feature/TenantDatabaseIsolationTest.php'),
        base_path('tests/Feature/TenantDatabaseManagerTest.php'),
        base_path('tests/Feature/TeamTenantIsolationTest.php'),
        base_path('tests/Feature/WorkspaceTenantIsolationTest.php'),
        base_path('tests/Feature/TeamDatabaseIsolationTest.php'),
        base_path('tests/Feature/WorkspaceDatabaseIsolationTest.php'),
        base_path('tests/Feature/TeamMembershipTest.php'),
        base_path('tests/Feature/TeamPolicyTest.php'),
        base_path('tests/Feature/TeamCrudTest.php'),
        base_path('tests/Feature/SetCurrentTeamMiddlewareTest.php'),
        base_path('tests/Feature/WorkspaceMembershipTest.php'),
        base_path('tests/Feature/WorkspacePolicyTest.php'),
        base_path('tests/Feature/WorkspaceCrudTest.php'),
        base_path('tests/Feature/SetCurrentWorkspaceMiddlewareTest.php'),
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $migrationPatterns = [
        '*_create_teams_table.php',
        '*_create_workspaces_table.php',
        '*_create_team_user_table.php',
        '*_create_workspace_user_table.php',
        '*_create_tenants_table.php',
        '*_add_database_to_tenants_table.php',
        '*_create_tenant_user_table.php',
    ];

    foreach ($migrationPatterns as $pattern) {
        foreach (glob(database_path("migrations/{$pattern}")) ?: [] as $file) {
            unlink($file);
        }
        foreach (glob(database_path("migrations/tenant/{$pattern}")) ?: [] as $file) {
            unlink($file);
        }
    }

    $tenantMigrationDir = database_path('migrations/tenant');
    if (is_dir($tenantMigrationDir) && count(scandir($tenantMigrationDir)) === 2) {
        rmdir($tenantMigrationDir);
    }

    foreach (['Teams', 'Workspaces', 'Tenants'] as $orgDir) {
        foreach ([
            app_path("Http/Controllers/{$orgDir}"),
            app_path("Actions/{$orgDir}"),
            app_path("Http/Requests/{$orgDir}"),
            app_path("Events/{$orgDir}"),
            app_path('Listeners'),
        ] as $dir) {
            if (is_dir($dir)) {
                foreach (glob("{$dir}/*.php") ?: [] as $file) {
                    unlink($file);
                }
                if (count(scandir($dir)) === 2) {
                    rmdir($dir);
                }
            }
        }
    }

    $requestsPath = app_path('Http/Requests');
    if (is_dir($requestsPath) && count(scandir($requestsPath)) === 2) {
        rmdir($requestsPath);
    }

    $eventsPath = app_path('Events');
    if (is_dir($eventsPath) && count(scandir($eventsPath)) === 2) {
        rmdir($eventsPath);
    }

    $listenersPath = app_path('Listeners');
    if (is_dir($listenersPath) && count(scandir($listenersPath)) === 2) {
        rmdir($listenersPath);
    }

    foreach (['UserTenantTeamObserver.php', 'UserTenantWorkspaceObserver.php'] as $observer) {
        $path = app_path("Observers/{$observer}");
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $observersPath = app_path('Observers');
    if (is_dir($observersPath) && count(scandir($observersPath)) === 2) {
        rmdir($observersPath);
    }

    $scopesPath = app_path('Models/Scopes');
    if (is_dir($scopesPath) && count(scandir($scopesPath)) === 2) {
        rmdir($scopesPath);
    }

    $policiesPath = app_path('Policies');
    if (is_dir($policiesPath) && count(scandir($policiesPath)) === 2) {
        rmdir($policiesPath);
    }

    $traitsPath = app_path('Traits');
    if (is_dir($traitsPath) && count(scandir($traitsPath)) === 2) {
        rmdir($traitsPath);
    }

    $servicesPath = app_path('Services');
    if (is_dir($servicesPath) && count(scandir($servicesPath)) === 2) {
        rmdir($servicesPath);
    }

    $commandsPath = app_path('Console/Commands');
    if (is_dir($commandsPath) && count(scandir($commandsPath)) === 2) {
        rmdir($commandsPath);
    }

    $consolePath = app_path('Console');
    if (is_dir($consolePath) && count(scandir($consolePath)) === 2) {
        rmdir($consolePath);
    }

    $jobConcernsPath = app_path('Jobs/Concerns');
    if (is_dir($jobConcernsPath) && count(scandir($jobConcernsPath)) === 2) {
        rmdir($jobConcernsPath);
    }

    $jobsPath = app_path('Jobs');
    if (is_dir($jobsPath) && count(scandir($jobsPath)) === 2) {
        rmdir($jobsPath);
    }

    restoreOriginalFile(app_path('Models/User.php'));
    restoreOriginalFile(base_path('routes/web.php'));
    restoreOriginalFile(base_path('bootstrap/providers.php'));
    restoreOriginalFile(database_path('seeders/DatabaseSeeder.php'));
    restoreOriginalFile(app_path('Http/Middleware/HandleInertiaRequests.php'));
    restoreOriginalFile(resource_path('js/components/AppSidebar.vue'));
}

function restoreOriginalFile(string $path): void
{
    $backup = $path . '.bak';
    if (file_exists($backup)) {
        copy($backup, $path);
        unlink($backup);
    }
}

function backupOriginalFiles(): void
{
    $files = [
        app_path('Models/User.php'),
        base_path('routes/web.php'),
        base_path('bootstrap/providers.php'),
        database_path('seeders/DatabaseSeeder.php'),
        app_path('Http/Middleware/HandleInertiaRequests.php'),
        resource_path('js/components/AppSidebar.vue'),
    ];

    foreach ($files as $file) {
        $backup = $file . '.bak';
        if (file_exists($file) && !file_exists($backup)) {
            copy($file, $backup);
        }
    }
}

it('prevents double initialization', function (): void {
    file_put_contents(config_path('nubos.php'), '<?php return [];');

    $this->artisan('nubos:init')
        ->expectsOutputToContain('already initialized')
        ->assertFailed();
});

it('generates team stubs with correct naming', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $config = include config_path('nubos.php');
    expect($config)->organization->toBe('team')
        ->and(app_path('Models/Team.php'))->toBeFile();

    $modelContent = file_get_contents(app_path('Models/Team.php'));
    expect($modelContent)
        ->toContain('#[UsePolicy(TeamPolicy::class)]')
        ->toContain('class Team extends Model')
        ->toContain("belongsToMany(User::class, 'team_user')")
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Traits/BelongsToTeam.php'))->toBeFile();

    $traitContent = file_get_contents(app_path('Traits/BelongsToTeam.php'));
    expect($traitContent)
        ->toContain('trait BelongsToTeam')
        ->toContain('function teams()')
        ->toContain('function ownsTeam(Team $team)')
        ->toContain('function belongsToTeam(Team $team)')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Http/Middleware/SetCurrentTeam.php'))->toBeFile();

    $middlewareContent = file_get_contents(app_path('Http/Middleware/SetCurrentTeam.php'));
    expect($middlewareContent)
        ->toContain('class SetCurrentTeam')
        ->toContain("->route('team')")
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(database_path('migrations/0001_01_01_100000_create_teams_table.php'))->toBeFile()
        ->and(database_path('migrations/0001_01_01_100001_create_team_user_table.php'))->toBeFile()
        ->and(database_path('factories/TeamFactory.php'))->toBeFile();

    $factoryContent = file_get_contents(database_path('factories/TeamFactory.php'));
    expect($factoryContent)
        ->toContain('class TeamFactory extends Factory')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(database_path('seeders/TeamSeeder.php'))->toBeFile()
        ->and(base_path('routes/team.php'))->toBeFile();

    $routeContent = file_get_contents(base_path('routes/team.php'));
    expect($routeContent)
        ->toContain('TeamController::class')
        ->toContain("'teams.index'")
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Http/Controllers/Teams/TeamController.php'))->toBeFile();

    $controller = file_get_contents(app_path('Http/Controllers/Teams/TeamController.php'));
    expect($controller)
        ->toContain('namespace App\Http\Controllers\Teams')
        ->toContain('function index(')
        ->toContain('function create(')
        ->toContain('function store(')
        ->toContain('function update(')
        ->toContain('function destroy(')
        ->toContain('CreateTeamAction')
        ->toContain('UpdateTeamAction')
        ->toContain('DeleteTeamAction')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->toContain('$this->authorize(')
        ->and(app_path('Actions/Teams/CreateTeamAction.php'))->toBeFile()
        ->and(app_path('Actions/Teams/UpdateTeamAction.php'))->toBeFile()
        ->and(app_path('Actions/Teams/DeleteTeamAction.php'))->toBeFile()
        ->and(app_path('Policies/TeamPolicy.php'))->toBeFile();

    $policy = file_get_contents(app_path('Policies/TeamPolicy.php'));
    expect($policy)
        ->toContain('class TeamPolicy')
        ->toContain('function update(User $user, Team $team)')
        ->toContain('function delete(User $user, Team $team)')
        ->toContain('$user->ownsTeam($team)')
        ->toContain('$team->is_personal')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}');
});

it('generates team switcher vue component', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(resource_path('js/components/TeamSwitcher.vue'))->toBeFile();
    $switcherContent = file_get_contents(resource_path('js/components/TeamSwitcher.vue'));
    expect($switcherContent)
        ->toContain('page.props.teams')
        ->toContain('page.props.currentTeam')
        ->toContain('/teams/${team.id}/dashboard')
        ->toContain('/teams/create')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}');
});

it('patches user model with team trait', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use App\\Traits\\BelongsToTeam;')
        ->toContain('use BelongsToTeam;');
});

it('registers service provider in bootstrap providers', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)
        ->toContain('NubosTeamServiceProvider::class')
        ->and(app_path('Providers/NubosTeamServiceProvider.php'))->toBeFile();

    $providerContent = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($providerContent)
        ->toContain('SetCurrentTeam::class')
        ->toContain("'set-current-team'")
        ->toContain("prefix('teams/{team}')")
        ->toContain('routes/app.php')
        ->toContain('routes/team.php');
});

it('extracts auth routes from web.php into routes/app.php', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $webContent = file_get_contents(base_path('routes/web.php'));
    expect($webContent)
        ->not->toContain("Route::inertia('dashboard'")
        ->toContain("Route::inertia('/'");

    expect(base_path('routes/app.php'))->toBeFile();
    $appContent = file_get_contents(base_path('routes/app.php'));
    expect($appContent)
        ->toContain("Route::inertia('dashboard'")
        ->toContain("->name('dashboard')");
});

it('patches database seeder to call team seeder', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $seederContent = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($seederContent)->toContain('TeamSeeder::class');
});

it('patches sidebar to include team switcher', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)
        ->toContain("import TeamSwitcher from '@/components/TeamSwitcher.vue'")
        ->toContain('<TeamSwitcher />');
});

it('shares organizations and current organization via inertia', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $inertiaContent = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($inertiaContent)
        ->toContain("'teams'")
        ->toContain("'currentTeam'")
        ->toContain('use App\\Models\\Team;');
});

it('generates workspace stubs with correct naming', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $config = include config_path('nubos.php');
    expect($config)->organization->toBe('workspace')
        ->and(app_path('Models/Workspace.php'))->toBeFile();

    $modelContent = file_get_contents(app_path('Models/Workspace.php'));
    expect($modelContent)
        ->toContain('#[UsePolicy(WorkspacePolicy::class)]')
        ->toContain('class Workspace extends Model')
        ->toContain("belongsToMany(User::class, 'workspace_user')")
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Traits/BelongsToWorkspace.php'))->toBeFile();

    $traitContent = file_get_contents(app_path('Traits/BelongsToWorkspace.php'));
    expect($traitContent)
        ->toContain('trait BelongsToWorkspace')
        ->toContain('function workspaces()')
        ->toContain('function ownsWorkspace(Workspace $workspace)')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Http/Middleware/SetCurrentWorkspace.php'))->toBeFile()
        ->and(database_path('migrations/0001_01_01_100000_create_workspaces_table.php'))->toBeFile()
        ->and(database_path('migrations/0001_01_01_100001_create_workspace_user_table.php'))->toBeFile()
        ->and(resource_path('js/components/WorkspaceSwitcher.vue'))->toBeFile();

    $switcherContent = file_get_contents(resource_path('js/components/WorkspaceSwitcher.vue'));
    expect($switcherContent)
        ->toContain('/workspaces/${workspace.id}/dashboard')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}');

    $routeContent = file_get_contents(base_path('routes/workspace.php'));
    expect($routeContent)
        ->toContain('WorkspaceController::class')
        ->toContain("'workspaces.index'")
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}')
        ->and(app_path('Http/Controllers/Workspaces/WorkspaceController.php'))->toBeFile();

    expect(app_path('Policies/WorkspacePolicy.php'))->toBeFile();

    $policy = file_get_contents(app_path('Policies/WorkspacePolicy.php'));
    expect($policy)
        ->toContain('class WorkspacePolicy')
        ->toContain('function update(User $user, Workspace $workspace)')
        ->toContain('$user->ownsWorkspace($workspace)')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organizations}}')
        ->not->toContain('{{Organizations}}');

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use BelongsToWorkspace;');
});

it('generates tenant base stubs', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Tenant.php'))->toBeFile();
    expect(app_path('Traits/TenantScope.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTenant.php'))->toBeFile();
    expect(app_path('Http/Middleware/IdentifyTenant.php'))->toBeFile();
    expect(app_path('Policies/TenantPolicy.php'))->toBeFile();
    expect(app_path('Providers/NubosTenantServiceProvider.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200000_create_tenants_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200001_create_tenant_user_table.php'))->toBeFile();
    expect(database_path('factories/TenantFactory.php'))->toBeFile();
    expect(database_path('seeders/TenantSeeder.php'))->toBeFile();
    expect(base_path('routes/tenant.php'))->toBeFile();

    expect(base_path('tests/Feature/TenantScopeTest.php'))->toBeFile();
    expect(base_path('tests/Feature/IdentifyTenantTest.php'))->toBeFile();

    $scopeTest = file_get_contents(base_path('tests/Feature/TenantScopeTest.php'));
    expect($scopeTest)
        ->toContain('scopes queries to the current tenant')
        ->toContain('belongsToTenant')
        ->toContain('ownsTenant');

    $identifyTest = file_get_contents(base_path('tests/Feature/IdentifyTenantTest.php'));
    expect($identifyTest)
        ->toContain('resolves tenant from subdomain')
        ->toContain('returns 404 for unknown tenant subdomain')
        ->toContain('redirects reserved subdomains to fallback');
});

it('generates tenant model with correct structure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
    expect($tenantModel)
        ->toContain('#[UsePolicy(TenantPolicy::class)]')
        ->toContain('class Tenant extends Model')
        ->toContain('use HasUuids;')
        ->toContain('use SoftDeletes;')
        ->toContain("belongsToMany(User::class, 'tenant_user')")
        ->not->toContain('HasMany');

    $tenantScope = file_get_contents(app_path('Traits/TenantScope.php'));
    expect($tenantScope)
        ->toContain('trait TenantScope')
        ->toContain('bootTenantScope')
        ->toContain("addGlobalScope('tenant'")
        ->toContain('currentTenant')
        ->toContain('tenant_id');
});

it('generates tenant identification middleware', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/IdentifyTenant.php'));
    expect($middleware)
        ->toContain('class IdentifyTenant')
        ->toContain('extractSubdomain')
        ->toContain("app()->instance('currentTenant'")
        ->toContain('redirectToFallback')
        ->toContain('isReservedSubdomain')
        ->toContain('abort(404)')
        ->not->toContain('use App\Models\Domain')
        ->toContain("config('app.domain')");
});

it('patches user model with tenant trait', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use App\\Traits\\BelongsToTenant;')
        ->toContain('use BelongsToTenant;');
});

it('registers tenant service provider in bootstrap providers', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)->toContain('NubosTenantServiceProvider::class');

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain('IdentifyTenant::class')
        ->toContain('identify-tenant')
        ->not->toContain('prependMiddlewareToGroup')
        ->toContain('routes/tenant.php');
});

it('patches database seeder to call tenant seeder', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $seederContent = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($seederContent)->toContain('TenantSeeder::class');
});

it('shares current tenant via inertia', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $inertiaContent = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($inertiaContent)
        ->toContain("'currentTenant'")
        ->toContain('use App\\Models\\Tenant;');
});

it('does not patch sidebar for tenant without substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)
        ->not->toContain('TenantSwitcher')
        ->not->toContain('TeamSwitcher');
});

it('extracts web routes for tenant without substructure into app.php', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $webContent = file_get_contents(base_path('routes/web.php'));
    expect($webContent)->not->toContain("Route::inertia('dashboard'");

    expect(base_path('routes/app.php'))->toBeFile();
    $appContent = file_get_contents(base_path('routes/app.php'));
    expect($appContent)->toContain("Route::inertia('dashboard'");
});

it('generates tenant with teams substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Tenant.php'))->toBeFile();
    expect(app_path('Models/Team.php'))->toBeFile();
    expect(app_path('Traits/TenantScope.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTenant.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTeam.php'))->toBeFile();

    $teamModel = file_get_contents(app_path('Models/Team.php'));
    expect($teamModel)
        ->toContain('use TenantScope;')
        ->toContain("'tenant_id'")
        ->toContain('class Team extends Model')
        ->not->toContain('owner_id')
        ->not->toContain('is_personal')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');

    expect(database_path('migrations/0001_01_01_200000_create_tenants_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200002_create_teams_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200003_create_team_user_table.php'))->toBeFile();

    $teamMigration = file_get_contents(database_path('migrations/0001_01_01_200002_create_teams_table.php'));
    expect($teamMigration)
        ->toContain('tenant_id')
        ->toContain('constrained');

    expect(base_path('tests/Feature/TeamTenantIsolationTest.php'))->toBeFile();
    $isolationTest = file_get_contents(base_path('tests/Feature/TeamTenantIsolationTest.php'));
    expect($isolationTest)
        ->toContain('scopes team queries to the current tenant')
        ->toContain('auto-sets tenant_id')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');
});

it('patches all files for tenant with substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use BelongsToTenant;')
        ->toContain('use BelongsToTeam;');

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)
        ->toContain('NubosTenantServiceProvider::class')
        ->toContain('NubosTeamServiceProvider::class');

    $seederContent = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($seederContent)
        ->toContain('TenantSeeder::class')
        ->toContain('TeamSeeder::class');

    $inertiaContent = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($inertiaContent)
        ->toContain("'currentTenant'")
        ->toContain("'teams'")
        ->toContain("'currentTeam'");

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)->toContain('TeamSwitcher');

    expect(base_path('routes/app.php'))->toBeFile();
});

it('generates tenant substructure without placeholder tokens', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $generatedFiles = [
        app_path('Models/Team.php'),
        app_path('Traits/BelongsToTeam.php'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Controllers/Teams/TeamController.php'),
        app_path('Actions/Teams/CreateTeamAction.php'),
        app_path('Actions/Teams/UpdateTeamAction.php'),
        app_path('Actions/Teams/DeleteTeamAction.php'),
        app_path('Policies/TeamPolicy.php'),
        app_path('Providers/NubosTeamServiceProvider.php'),
        database_path('factories/TeamFactory.php'),
        database_path('seeders/TeamSeeder.php'),
        base_path('routes/team.php'),
        resource_path('js/components/TeamSwitcher.vue'),
        database_path('migrations/0001_01_01_200002_create_teams_table.php'),
        database_path('migrations/0001_01_01_200003_create_team_user_table.php'),
        base_path('tests/Feature/TeamTenantIsolationTest.php'),
    ];

    foreach ($generatedFiles as $file) {
        expect($file)->toBeFile()
            ->and(file_get_contents($file))->not->toContain('{{organization}}')
            ->not->toContain('{{Organization}}')
            ->not->toContain('{{organizations}}')
            ->not->toContain('{{Organizations}}');
    }
});

it('initializes tenant with full options', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $config = include config_path('nubos.php');

    expect($config)
        ->organization->toBe('tenant')
        ->database_strategy->toBe('multi')
        ->tenant_substructure->toBe(['teams', 'workspaces'])
        ->tenant_fallback_url->toBe('/')
        ->reserved_subdomains->toBe(['www', 'docs', 'api', 'mail', 'ftp']);

    expect(app_path('Models/Team.php'))->toBeFile();
    expect(app_path('Models/Workspace.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTeam.php'))->toBeFile();
    expect(app_path('Traits/BelongsToWorkspace.php'))->toBeFile();
    expect(app_path('Traits/UsesTenantConnection.php'))->toBeFile();
    expect(app_path('Services/TenantDatabaseManager.php'))->toBeFile();
    expect(app_path('Console/Commands/TenantMigrateCommand.php'))->toBeFile();
});

it('never leaves placeholder tokens in generated files', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $generatedFiles = [
        app_path('Models/Team.php'),
        app_path('Traits/BelongsToTeam.php'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Controllers/Teams/TeamController.php'),
        app_path('Actions/Teams/CreateTeamAction.php'),
        app_path('Actions/Teams/UpdateTeamAction.php'),
        app_path('Actions/Teams/DeleteTeamAction.php'),
        app_path('Policies/TeamPolicy.php'),
        app_path('Providers/NubosTeamServiceProvider.php'),
        database_path('factories/TeamFactory.php'),
        database_path('seeders/TeamSeeder.php'),
        base_path('routes/team.php'),
        resource_path('js/components/TeamSwitcher.vue'),
        database_path('migrations/0001_01_01_100000_create_teams_table.php'),
        database_path('migrations/0001_01_01_100001_create_team_user_table.php'),
    ];

    foreach ($generatedFiles as $file) {
        expect($file)->toBeFile()
            ->and(file_get_contents($file))->not->toContain('{{organization}}')
            ->not->toContain('{{Organization}}')
            ->not->toContain('{{organizations}}')
            ->not->toContain('{{Organizations}}');
    }
});

it('generates multi-db tenant stubs with database field', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Tenant.php'))->toBeFile();

    $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
    expect($tenantModel)
        ->toContain('use ConfiguresTenantDatabase;')
        ->toContain('class Tenant extends Model');

    expect(app_path('Traits/ConfiguresTenantDatabase.php'))->toBeFile();
    $dbTrait = file_get_contents(app_path('Traits/ConfiguresTenantDatabase.php'));
    expect($dbTrait)
        ->toContain('configureDatabaseConnection')
        ->toContain('DB::purge')
        ->not->toContain("mergeFillable(['database'])");

    expect(app_path('Traits/UsesTenantConnection.php'))->toBeFile();
    $traitContent = file_get_contents(app_path('Traits/UsesTenantConnection.php'));
    expect($traitContent)
        ->toContain('trait UsesTenantConnection')
        ->toContain("return 'tenant'");

    expect(app_path('Traits/TenantScope.php'))->not->toBeFile();
});

it('generates multi-db tenant database manager', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Services/TenantDatabaseManager.php'))->toBeFile();

    $manager = file_get_contents(app_path('Services/TenantDatabaseManager.php'));
    expect($manager)
        ->toContain('class TenantDatabaseManager')
        ->toContain('createDatabase')
        ->toContain('dropDatabase')
        ->toContain('migrate')
        ->toContain('migrateAll')
        ->toContain('assertValidDatabaseName')
        ->toContain('CREATE DATABASE')
        ->toContain("'--database' => 'tenant'")
        ->toContain("'--path' => \$migrationPath");
});

it('generates multi-db tenant migrate command', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Console/Commands/TenantMigrateCommand.php'))->toBeFile();

    $command = file_get_contents(app_path('Console/Commands/TenantMigrateCommand.php'));
    expect($command)
        ->toContain('class TenantMigrateCommand')
        ->toContain("'tenant:migrate")
        ->toContain('TenantDatabaseManager');
});

it('generates multi-db tenants migration with database column', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(database_path('migrations/0001_01_01_200000_create_tenants_table.php'))->toBeFile();

    $baseMigration = file_get_contents(database_path('migrations/0001_01_01_200000_create_tenants_table.php'));
    expect($baseMigration)
        ->not->toContain("config('nubos.database_strategy')");

    expect(database_path('migrations/0001_01_01_200002_add_database_to_tenants_table.php'))->toBeFile();

    $dbMigration = file_get_contents(database_path('migrations/0001_01_01_200002_add_database_to_tenants_table.php'));
    expect($dbMigration)
        ->toContain("->string('database')->nullable()->unique()");
});

it('generates multi-db middleware that configures database connection', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/IdentifyTenant.php'));
    expect($middleware)
        ->toContain('configureDatabaseConnection')
        ->toContain('class IdentifyTenant');
});

it('generates multi-db service provider with tenant migrate command', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $provider = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($provider)
        ->toContain('TenantMigrateCommand::class')
        ->toContain('IdentifyTenant::class');
});

it('generates multi-db factory with database field', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $factory = file_get_contents(database_path('factories/TenantFactory.php'));
    expect($factory)
        ->toContain("'database'")
        ->toContain("'tenant_'");
});

it('generates multi-db seeder that creates database', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $seeder = file_get_contents(database_path('seeders/TenantSeeder.php'));
    expect($seeder)
        ->toContain("config('nubos.database_strategy') === 'multi'")
        ->toContain('TenantDatabaseManager');
});

it('generates multi-db test stubs', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(base_path('tests/Feature/IdentifyTenantTest.php'))->toBeFile();
    $identifyTest = file_get_contents(base_path('tests/Feature/IdentifyTenantTest.php'));
    expect($identifyTest)
        ->toContain('configures tenant database connection on identification')
        ->toContain('tenant_acme');

    expect(base_path('tests/Feature/TenantDatabaseIsolationTest.php'))->toBeFile();
    $isolationTest = file_get_contents(base_path('tests/Feature/TenantDatabaseIsolationTest.php'));
    expect($isolationTest)
        ->toContain('configures unique database per tenant')
        ->toContain('switches database connection');

    expect(base_path('tests/Feature/TenantDatabaseManagerTest.php'))->toBeFile();
    $managerTest = file_get_contents(base_path('tests/Feature/TenantDatabaseManagerTest.php'));
    expect($managerTest)
        ->toContain('creates a database')
        ->toContain('TenantDatabaseManager');
});

it('generates multi-db tenant with teams substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Tenant.php'))->toBeFile();
    expect(app_path('Models/Team.php'))->toBeFile();
    expect(app_path('Traits/UsesTenantConnection.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTenant.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTeam.php'))->toBeFile();
    expect(app_path('Traits/TenantScope.php'))->not->toBeFile();

    $teamModel = file_get_contents(app_path('Models/Team.php'));
    expect($teamModel)
        ->toContain('use UsesTenantConnection;')
        ->not->toContain('use TenantScope;')
        ->not->toContain("'tenant_id'")
        ->toContain('class Team extends Model')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');

    expect(database_path('migrations/tenant/0001_01_01_000001_create_teams_table.php'))->toBeFile();
    expect(database_path('migrations/tenant/0001_01_01_000002_create_team_user_table.php'))->toBeFile();

    $teamMigration = file_get_contents(database_path('migrations/tenant/0001_01_01_000001_create_teams_table.php'));
    expect($teamMigration)
        ->not->toContain('tenant_id')
        ->toContain("'slug', 100)->unique()")
        ->toContain("protected \$connection = 'tenant'");

    $pivotMigration = file_get_contents(database_path('migrations/tenant/0001_01_01_000002_create_team_user_table.php'));
    expect($pivotMigration)
        ->toContain("protected \$connection = 'tenant'")
        ->not->toContain('constrained()->cascadeOnDelete()');

    expect(base_path('tests/Feature/TeamDatabaseIsolationTest.php'))->toBeFile();
    $isolationTest = file_get_contents(base_path('tests/Feature/TeamDatabaseIsolationTest.php'));
    expect($isolationTest)
        ->toContain('uses tenant database connection')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');
});

it('generates multi-db substructure without placeholder tokens', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $generatedFiles = [
        app_path('Models/Team.php'),
        app_path('Traits/BelongsToTeam.php'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Controllers/Teams/TeamController.php'),
        app_path('Actions/Teams/CreateTeamAction.php'),
        app_path('Actions/Teams/UpdateTeamAction.php'),
        app_path('Actions/Teams/DeleteTeamAction.php'),
        app_path('Policies/TeamPolicy.php'),
        app_path('Providers/NubosTeamServiceProvider.php'),
        database_path('factories/TeamFactory.php'),
        database_path('seeders/TeamSeeder.php'),
        base_path('routes/team.php'),
        resource_path('js/components/TeamSwitcher.vue'),
        database_path('migrations/tenant/0001_01_01_000001_create_teams_table.php'),
        database_path('migrations/tenant/0001_01_01_000002_create_team_user_table.php'),
        base_path('tests/Feature/TeamDatabaseIsolationTest.php'),
    ];

    foreach ($generatedFiles as $file) {
        expect($file)->toBeFile()
            ->and(file_get_contents($file))->not->toContain('{{organization}}')
            ->not->toContain('{{Organization}}')
            ->not->toContain('{{organizations}}')
            ->not->toContain('{{Organizations}}');
    }
});

it('generates multi-db sub create action without tenant_id', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->not->toContain('tenant_id')
        ->toContain("abort_unless(app()->bound('currentTenant')")
        ->toContain("DB::connection('tenant')->transaction")
        ->toContain('CreateTeamAction');
});

it('generates multi-db sub factory without tenant_id', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $factory = file_get_contents(database_path('factories/TeamFactory.php'));
    expect($factory)
        ->not->toContain('tenant_id')
        ->not->toContain('Tenant::factory()')
        ->toContain('TeamFactory');
});

it('generates identify tenant middleware that aborts on missing domain config', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/IdentifyTenant.php'));
    expect($middleware)
        ->toContain('abort(500')
        ->toContain('Tenant identification requires a configured app domain');
});

it('generates multi-db identify tenant middleware that aborts on missing domain config', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/IdentifyTenant.php'));
    expect($middleware)
        ->toContain('abort(500')
        ->toContain('Tenant identification requires a configured app domain');
});

it('generates unique migration timestamps when both sub-orgs are selected (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(database_path('migrations/0001_01_01_200002_create_teams_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200003_create_team_user_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200004_create_workspaces_table.php'))->toBeFile();
    expect(database_path('migrations/0001_01_01_200005_create_workspace_user_table.php'))->toBeFile();
});

it('generates unique migration timestamps when both sub-orgs are selected (multi-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(database_path('migrations/tenant/0001_01_01_000001_create_teams_table.php'))->toBeFile();
    expect(database_path('migrations/tenant/0001_01_01_000002_create_team_user_table.php'))->toBeFile();
    expect(database_path('migrations/tenant/0001_01_01_000003_create_workspaces_table.php'))->toBeFile();
    expect(database_path('migrations/tenant/0001_01_01_000004_create_workspace_user_table.php'))->toBeFile();
});

it('generates single-db sub-org policy with tenant context check', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $policy = file_get_contents(app_path('Policies/TeamPolicy.php'));
    expect($policy)
        ->toContain('belongsToCurrentTenant')
        ->toContain('$team->tenant_id === app(')
        ->toContain('belongsToTenant')
        ->not->toContain('is_personal');
});

it('generates multi-db sub-org policy with tenant context check', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $policy = file_get_contents(app_path('Policies/TeamPolicy.php'));
    expect($policy)
        ->toContain('userBelongsToCurrentTenant')
        ->toContain("app()->bound('currentTenant')")
        ->toContain('belongsToTenant')
        ->not->toContain('is_personal')
        ->not->toContain('tenant_id ===');
});

it('generates set current middleware with tenant context guard', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/SetCurrentTeam.php'));
    expect($middleware)
        ->toContain("app()->bound('currentTenant')")
        ->toContain('$team->tenant_id !== $currentTenant->id');
});

it('patches tenant model with hasMany relation for sub-orgs (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
    expect($tenantModel)
        ->toContain('function teams(): HasMany')
        ->toContain('hasMany(Team::class)')
        ->toContain('use Illuminate\Database\Eloquent\Relations\HasMany;');
});

it('generates standalone trait with proper eloquent relation for owned organizations', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $traitContent = file_get_contents(app_path('Traits/BelongsToTeam.php'));
    expect($traitContent)
        ->toContain('function ownedTeams(): HasMany')
        ->toContain("hasMany(Team::class, 'owner_id')")
        ->toContain('use Illuminate\Database\Eloquent\Relations\HasMany;');
});

it('does not patch tenant model with hasMany for multi-db sub-orgs', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
    expect($tenantModel)
        ->not->toContain('HasMany')
        ->not->toContain('function teams()');
});

it('generates multi-db sub-org trait with cross-db pivot table', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $traitContent = file_get_contents(app_path('Traits/BelongsToTeam.php'));
    expect($traitContent)
        ->toContain('team_user')
        ->toContain('belongsToMany')
        ->toContain('using(TeamUser::class)')
        ->toContain("wherePivot('role', 'owner')")
        ->not->toContain("config('database.connections.tenant.database')")
        ->not->toContain('ownedTeams')
        ->not->toContain('personalTeam')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organization}}');

    expect(app_path('Models/TeamUser.php'))->toBeFile();
    $pivotContent = file_get_contents(app_path('Models/TeamUser.php'));
    expect($pivotContent)
        ->toContain('extends Pivot')
        ->toContain('UsesTenantConnection')
        ->not->toContain('{{Organization}}');
});

it('generates tenant sub-org service provider with identify-tenant middleware', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providerContent = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($providerContent)
        ->toContain("'identify-tenant'")
        ->toContain('set-current-team')
        ->toContain('routes/app.php')
        ->toContain('routes/team.php')
        ->not->toContain('prependMiddlewareToGroup')
        ->not->toContain('{{Organization}}')
        ->not->toContain('{{organization}}');
});

it('does not include identify-tenant in standalone service provider', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $providerContent = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($providerContent)
        ->toContain('set-current-team')
        ->not->toContain('identify-tenant');
});

it('generates identify-tenant as route middleware not global middleware', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain("'identify-tenant'")
        ->toContain("Route::middleware(['web', 'auth', 'verified'])")
        ->not->toContain('prependMiddlewareToGroup');
});

it('generates form requests for organization crud', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(app_path('Http/Requests/Teams/StoreTeamRequest.php'))->toBeFile();
    expect(app_path('Http/Requests/Teams/UpdateTeamRequest.php'))->toBeFile();

    $storeRequest = file_get_contents(app_path('Http/Requests/Teams/StoreTeamRequest.php'));
    expect($storeRequest)
        ->toContain('class StoreTeamRequest extends FormRequest')
        ->toContain("'name' => ['required', 'string', 'max:255']")
        ->not->toContain('{{Organization}}');

    $updateRequest = file_get_contents(app_path('Http/Requests/Teams/UpdateTeamRequest.php'));
    expect($updateRequest)
        ->toContain('class UpdateTeamRequest extends FormRequest')
        ->not->toContain('{{Organization}}');
});

it('generates controller using form requests instead of raw input', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $controllerContent = file_get_contents(app_path('Http/Controllers/Teams/TeamController.php'));
    expect($controllerContent)
        ->toContain('StoreTeamRequest $request')
        ->toContain('UpdateTeamRequest $request')
        ->toContain('$request->validated()')
        ->not->toContain('$request->all()');
});

it('generates actions without inline validation', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->not->toContain('Validator::validate')
        ->toContain('array $data');

    $updateAction = file_get_contents(app_path('Actions/Teams/UpdateTeamAction.php'));
    expect($updateAction)
        ->not->toContain('Validator::validate')
        ->toContain('array $data');
});

it('generates delete action without pivot detach for soft delete consistency', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $deleteAction = file_get_contents(app_path('Actions/Teams/DeleteTeamAction.php'));
    expect($deleteAction)
        ->toContain('$team->delete()')
        ->not->toContain('detach()');
});

it('generates tenant-single-db create action with defensive tenant check', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->toContain("abort_unless(app()->bound('currentTenant')")
        ->toContain('array $data')
        ->not->toContain('Validator::validate');
});

it('generates multi-db create action with defensive tenant check', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->toContain("abort_unless(app()->bound('currentTenant')")
        ->toContain("DB::connection('tenant')->transaction")
        ->not->toContain('Validator::validate');
});

it('generates db-agnostic tenant database manager', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $managerContent = file_get_contents(app_path('Services/TenantDatabaseManager.php'));
    expect($managerContent)
        ->toContain("'mysql'")
        ->toContain("'pgsql'")
        ->toContain('createPostgresDatabase')
        ->toContain('pg_database')
        ->toContain('Log::error');
});

it('generates configures tenant database trait with phpstan property annotation', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $traitContent = file_get_contents(app_path('Traits/ConfiguresTenantDatabase.php'));
    expect($traitContent)
        ->toContain('@property string $database');
});

it('generates factory with afterCreating that attaches owner as member', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $factoryContent = file_get_contents(database_path('factories/TeamFactory.php'));
    expect($factoryContent)
        ->toContain('afterCreating')
        ->toContain('->attach(')
        ->toContain("'role' => 'owner'");
});

it('generates tenant-single-db factory with afterCreating', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $factoryContent = file_get_contents(database_path('factories/TeamFactory.php'));
    expect($factoryContent)
        ->toContain('afterCreating')
        ->toContain("'role' => 'owner'");
});

it('generates onboarding listener for standalone organization', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(app_path('Listeners/CreatePersonalTeam.php'))->toBeFile();

    $listenerContent = file_get_contents(app_path('Listeners/CreatePersonalTeam.php'));
    expect($listenerContent)
        ->toContain('Illuminate\Auth\Events\Registered')
        ->toContain('CreateTeamAction')
        ->toContain("'is_personal' => true")
        ->not->toContain('{{Organization}}');
});

it('generates lifecycle events for organization', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(app_path('Events/Teams/TeamCreated.php'))->toBeFile();
    expect(app_path('Events/Teams/TeamDeleted.php'))->toBeFile();
    expect(app_path('Events/Teams/MemberAdded.php'))->toBeFile();
    expect(app_path('Events/Teams/MemberRemoved.php'))->toBeFile();

    $createdEvent = file_get_contents(app_path('Events/Teams/TeamCreated.php'));
    expect($createdEvent)
        ->toContain('class TeamCreated')
        ->toContain('use Dispatchable')
        ->toContain('Team $team')
        ->not->toContain('{{Organization}}');

    $memberEvent = file_get_contents(app_path('Events/Teams/MemberAdded.php'));
    expect($memberEvent)
        ->toContain('Team $team')
        ->toContain('User $user')
        ->toContain('string $role');
});

it('dispatches events in create action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->toContain('TeamCreated::dispatch')
        ->toContain('MemberAdded::dispatch');

    $deleteAction = file_get_contents(app_path('Actions/Teams/DeleteTeamAction.php'));
    expect($deleteAction)
        ->toContain('TeamDeleted::dispatch');
});

it('generates slug retry mechanism in create action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->toContain('UniqueConstraintViolationException')
        ->toContain('MAX_SLUG_RETRIES')
        ->toContain('executeWithSlugRetry');
});

it('generates migrations with down method', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_teams_table.php'));
    expect($migrations)->not->toBeEmpty();
    $migrationContent = file_get_contents($migrations[0]);
    expect($migrationContent)
        ->toContain('public function down(): void')
        ->toContain("Schema::dropIfExists('teams')");

    $pivotMigrations = glob(database_path('migrations/*_create_team_user_table.php'));
    expect($pivotMigrations)->not->toBeEmpty();
    $pivotContent = file_get_contents($pivotMigrations[0]);
    expect($pivotContent)
        ->toContain('public function down(): void')
        ->toContain("Schema::dropIfExists('team_user')");
});

it('generates tenant migrations with down method', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_tenants_table.php'));
    expect($migrations)->not->toBeEmpty();
    $migrationContent = file_get_contents($migrations[0]);
    expect($migrationContent)
        ->toContain('public function down(): void')
        ->toContain("Schema::dropIfExists('tenants')");
});

it('generates multi-db user observer for cross-db pivot cleanup', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Observers/UserTenantTeamObserver.php'))->toBeFile();

    $observerContent = file_get_contents(app_path('Observers/UserTenantTeamObserver.php'));
    expect($observerContent)
        ->toContain('function deleting(User $user)')
        ->toContain('CleanupUserTeamMembershipsJob::dispatch')
        ->not->toContain('Tenant::query()->each')
        ->not->toContain('{{Organization}}');

    expect(app_path('Jobs/CleanupUserTeamMembershipsJob.php'))->toBeFile();
    $jobContent = file_get_contents(app_path('Jobs/CleanupUserTeamMembershipsJob.php'));
    expect($jobContent)
        ->toContain('implements ShouldQueue')
        ->toContain("DB::connection('tenant')")
        ->toContain('configureDatabaseConnection')
        ->toContain('Log::warning')
        ->not->toContain('{{Organization}}');

    $providerContent = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($providerContent)
        ->toContain('User::observe(UserTenantTeamObserver::class)');
});

it('does not generate observer for single-db tenant sub-orgs', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Observers/UserTenantTeamObserver.php'))->not->toBeFile();

    $providerContent = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($providerContent)->not->toContain('Observer');
});

it('generates tenant crud infrastructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Http/Controllers/Tenants/TenantController.php'))->toBeFile();
    expect(app_path('Http/Requests/Tenants/StoreTenantRequest.php'))->toBeFile();
    expect(app_path('Http/Requests/Tenants/UpdateTenantRequest.php'))->toBeFile();
    expect(app_path('Actions/Tenants/CreateTenantAction.php'))->toBeFile();
    expect(app_path('Actions/Tenants/UpdateTenantAction.php'))->toBeFile();
    expect(app_path('Actions/Tenants/DeleteTenantAction.php'))->toBeFile();
    expect(app_path('Events/Tenants/TenantCreated.php'))->toBeFile();
    expect(app_path('Events/Tenants/TenantDeleted.php'))->toBeFile();
});

it('generates tenant controller with inertia and form requests', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $controllerContent = file_get_contents(app_path('Http/Controllers/Tenants/TenantController.php'));
    expect($controllerContent)
        ->toContain('StoreTenantRequest $request')
        ->toContain('UpdateTenantRequest $request')
        ->toContain('$request->validated()')
        ->toContain('Inertia::render')
        ->toContain('$this->authorize')
        ->not->toContain('$request->all()');
});

it('generates tenant create action with slug retry and event', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Tenants/CreateTenantAction.php'));
    expect($createAction)
        ->toContain('UniqueConstraintViolationException')
        ->toContain('executeWithSlugRetry')
        ->toContain("->attach(\$user, ['role' => 'owner'])")
        ->toContain('TenantCreated::dispatch');
});

it('generates multi-db tenant create action with database manager', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Tenants/CreateTenantAction.php'));
    expect($createAction)
        ->toContain('TenantDatabaseManager')
        ->toContain('databaseManager->createDatabase')
        ->toContain("'database' => 'tenant_'");
});

it('generates tenant routes without identify-tenant middleware', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $routesContent = file_get_contents(base_path('routes/tenant.php'));
    expect($routesContent)
        ->toContain('TenantController::class')
        ->toContain("'tenants.index'")
        ->toContain("'tenants.create'")
        ->toContain("'tenants.store'")
        ->toContain("'tenants.settings'")
        ->toContain("'tenants.update'")
        ->toContain("'tenants.destroy'");

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain("Route::middleware(['web', 'auth', 'verified'])")
        ->toContain('routes/tenant.php');
});

it('generates tenant delete action with soft delete and event', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $deleteAction = file_get_contents(app_path('Actions/Tenants/DeleteTenantAction.php'));
    expect($deleteAction)
        ->toContain('$tenant->members()->detach()')
        ->toContain('$tenant->delete()')
        ->toContain('TenantDeleted::dispatch');
});

// ──────────────────────────────────────────────────
// Fix 1: TenantAware DB-Connection for Multi-DB
// ──────────────────────────────────────────────────

it('generates tenant aware trait with query builder and db connection config', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $traitContent = file_get_contents(app_path('Jobs/Concerns/TenantAware.php'));
    expect($traitContent)
        ->toContain('Tenant::query()->findOrFail')
        ->not->toContain('Tenant::findOrFail($this')
        ->toContain('configureDatabaseConnection')
        ->toContain("method_exists(\$tenant, 'configureDatabaseConnection')");
});

// ──────────────────────────────────────────────────
// Fix 2: Settings Route for Organizations
// ──────────────────────────────────────────────────

it('generates settings route and show method for organizations', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $routeContent = file_get_contents(base_path('routes/team.php'));
    expect($routeContent)
        ->toContain("'teams.settings'")
        ->toContain("TeamController::class, 'settings'");

    $controllerContent = file_get_contents(app_path('Http/Controllers/Teams/TeamController.php'));
    expect($controllerContent)
        ->toContain('function settings(Team $team): Response')
        ->toContain("Inertia::render('Teams/Settings'")
        ->toContain("'team' => \$team");
});

it('generates settings route for workspace organizations', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $routeContent = file_get_contents(base_path('routes/workspace.php'));
    expect($routeContent)
        ->toContain("'workspaces.settings'")
        ->toContain("WorkspaceController::class, 'settings'");

    $controllerContent = file_get_contents(app_path('Http/Controllers/Workspaces/WorkspaceController.php'));
    expect($controllerContent)
        ->toContain('function settings(Workspace $workspace): Response')
        ->toContain("Inertia::render('Workspaces/Settings'");
});

// ──────────────────────────────────────────────────
// Fix 3: P3/P5 Subdomain Routes
// ──────────────────────────────────────────────────

it('generates subdomain routes for tenant without substructure (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain("Route::domain('{tenant}.' . config('app.domain'))")
        ->toContain("'identify-tenant'")
        ->toContain('routes/app.php');

    expect(base_path('routes/app.php'))->toBeFile();
});

it('generates subdomain routes for tenant without substructure (multi-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain("Route::domain('{tenant}.' . config('app.domain'))")
        ->toContain("'identify-tenant'")
        ->toContain('routes/app.php');
});

// ──────────────────────────────────────────────────
// Fix 4: No duplicate routes/app.php with both sub-orgs
// ──────────────────────────────────────────────────

it('loads app routes only from first sub-org provider when both selected (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $teamProvider = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($teamProvider)
        ->toContain("config('nubos.tenant_substructure', [])")
        ->toContain("in_array('teams', \$substructure, true)")
        ->toContain('routes/app.php');

    $workspaceProvider = file_get_contents(app_path('Providers/NubosWorkspaceServiceProvider.php'));
    expect($workspaceProvider)
        ->toContain("in_array('workspaces', \$substructure, true)")
        ->toContain('routes/app.php');
});

it('loads app routes only from first sub-org provider when both selected (multi-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $teamProvider = file_get_contents(app_path('Providers/NubosTeamServiceProvider.php'));
    expect($teamProvider)
        ->toContain("in_array('teams', \$substructure, true)")
        ->toContain('routes/app.php');

    $workspaceProvider = file_get_contents(app_path('Providers/NubosWorkspaceServiceProvider.php'));
    expect($workspaceProvider)
        ->toContain("in_array('workspaces', \$substructure, true)");
});

// ──────────────────────────────────────────────────
// Fix 5: Updated Events + RemoveMemberAction
// ──────────────────────────────────────────────────

it('dispatches updated event in update action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $updateAction = file_get_contents(app_path('Actions/Teams/UpdateTeamAction.php'));
    expect($updateAction)
        ->toContain('TeamUpdated::dispatch')
        ->toContain('use App\Events\Teams\TeamUpdated;');

    expect(app_path('Events/Teams/TeamUpdated.php'))->toBeFile();
    $event = file_get_contents(app_path('Events/Teams/TeamUpdated.php'));
    expect($event)
        ->toContain('class TeamUpdated')
        ->toContain('use Dispatchable')
        ->toContain('Team $team');
});

it('dispatches updated event in tenant update action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $updateAction = file_get_contents(app_path('Actions/Tenants/UpdateTenantAction.php'));
    expect($updateAction)
        ->toContain('TenantUpdated::dispatch')
        ->toContain('use App\Events\Tenants\TenantUpdated;');

    expect(app_path('Events/Tenants/TenantUpdated.php'))->toBeFile();
    $event = file_get_contents(app_path('Events/Tenants/TenantUpdated.php'));
    expect($event)
        ->toContain('class TenantUpdated')
        ->toContain('use Dispatchable')
        ->toContain('Tenant $tenant');
});

it('generates remove member action that dispatches member removed event', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(app_path('Actions/Teams/RemoveTeamMemberAction.php'))->toBeFile();

    $action = file_get_contents(app_path('Actions/Teams/RemoveTeamMemberAction.php'));
    expect($action)
        ->toContain('class RemoveTeamMemberAction')
        ->toContain('MemberRemoved::dispatch')
        ->toContain('->detach($user)');
});

// ──────────────────────────────────────────────────
// Fix 6: database field mass-assignment protection
// ──────────────────────────────────────────────────

it('sets database field via create in multi-db create action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Tenants/CreateTenantAction.php'));
    expect($createAction)
        ->not->toContain('forceFill')
        ->toContain("'database' => 'tenant_'");

    $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
    expect($tenantModel)
        ->toContain("'database'");

    $dbTrait = file_get_contents(app_path('Traits/ConfiguresTenantDatabase.php'));
    expect($dbTrait)
        ->not->toContain('mergeFillable')
        ->not->toContain('initializeConfiguresTenantDatabase');
});

// ──────────────────────────────────────────────────
// Fix 7: Workspace Test-Parität
// ──────────────────────────────────────────────────

it('patches user model with workspace trait', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use App\\Traits\\BelongsToWorkspace;')
        ->toContain('use BelongsToWorkspace;');
});

it('registers workspace service provider in bootstrap providers', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)->toContain('NubosWorkspaceServiceProvider::class');

    $providerContent = file_get_contents(app_path('Providers/NubosWorkspaceServiceProvider.php'));
    expect($providerContent)
        ->toContain('SetCurrentWorkspace::class')
        ->toContain("'set-current-workspace'")
        ->toContain("prefix('workspaces/{workspace}')")
        ->toContain('routes/app.php')
        ->toContain('routes/workspace.php');
});

it('extracts auth routes from web.php for workspace', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $webContent = file_get_contents(base_path('routes/web.php'));
    expect($webContent)->not->toContain("Route::inertia('dashboard'");

    expect(base_path('routes/app.php'))->toBeFile();
    $appContent = file_get_contents(base_path('routes/app.php'));
    expect($appContent)->toContain("Route::inertia('dashboard'");
});

it('patches database seeder to call workspace seeder', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $seederContent = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($seederContent)->toContain('WorkspaceSeeder::class');
});

it('patches sidebar to include workspace switcher', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)
        ->toContain("import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue'")
        ->toContain('<WorkspaceSwitcher />');
});

it('shares workspaces and current workspace via inertia', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $inertiaContent = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($inertiaContent)
        ->toContain("'workspaces'")
        ->toContain("'currentWorkspace'")
        ->toContain('use App\\Models\\Workspace;');
});

it('generates form requests for workspace crud', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    expect(app_path('Http/Requests/Workspaces/StoreWorkspaceRequest.php'))->toBeFile();
    expect(app_path('Http/Requests/Workspaces/UpdateWorkspaceRequest.php'))->toBeFile();

    $storeRequest = file_get_contents(app_path('Http/Requests/Workspaces/StoreWorkspaceRequest.php'));
    expect($storeRequest)
        ->toContain('class StoreWorkspaceRequest extends FormRequest')
        ->not->toContain('{{Organization}}');
});

it('generates workspace controller using form requests', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $controllerContent = file_get_contents(app_path('Http/Controllers/Workspaces/WorkspaceController.php'));
    expect($controllerContent)
        ->toContain('StoreWorkspaceRequest $request')
        ->toContain('UpdateWorkspaceRequest $request')
        ->toContain('$request->validated()')
        ->not->toContain('$request->all()');
});

it('generates workspace actions without inline validation', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Workspaces/CreateWorkspaceAction.php'));
    expect($createAction)
        ->not->toContain('Validator::validate')
        ->toContain('array $data');

    $updateAction = file_get_contents(app_path('Actions/Workspaces/UpdateWorkspaceAction.php'));
    expect($updateAction)
        ->not->toContain('Validator::validate')
        ->toContain('array $data');
});

it('dispatches events in workspace create action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Workspaces/CreateWorkspaceAction.php'));
    expect($createAction)
        ->toContain('WorkspaceCreated::dispatch')
        ->toContain('MemberAdded::dispatch');

    $deleteAction = file_get_contents(app_path('Actions/Workspaces/DeleteWorkspaceAction.php'));
    expect($deleteAction)
        ->toContain('WorkspaceDeleted::dispatch');
});

it('generates lifecycle events for workspace', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    expect(app_path('Events/Workspaces/WorkspaceCreated.php'))->toBeFile();
    expect(app_path('Events/Workspaces/WorkspaceDeleted.php'))->toBeFile();
    expect(app_path('Events/Workspaces/WorkspaceUpdated.php'))->toBeFile();
    expect(app_path('Events/Workspaces/MemberAdded.php'))->toBeFile();
    expect(app_path('Events/Workspaces/MemberRemoved.php'))->toBeFile();

    $createdEvent = file_get_contents(app_path('Events/Workspaces/WorkspaceCreated.php'));
    expect($createdEvent)
        ->toContain('class WorkspaceCreated')
        ->toContain('use Dispatchable')
        ->toContain('Workspace $workspace')
        ->not->toContain('{{Organization}}');
});

it('generates slug retry mechanism in workspace create action', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $createAction = file_get_contents(app_path('Actions/Workspaces/CreateWorkspaceAction.php'));
    expect($createAction)
        ->toContain('UniqueConstraintViolationException')
        ->toContain('MAX_SLUG_RETRIES')
        ->toContain('executeWithSlugRetry');
});

it('generates onboarding listener for workspace', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    expect(app_path('Listeners/CreatePersonalWorkspace.php'))->toBeFile();

    $listenerContent = file_get_contents(app_path('Listeners/CreatePersonalWorkspace.php'));
    expect($listenerContent)
        ->toContain('Illuminate\Auth\Events\Registered')
        ->toContain('CreateWorkspaceAction')
        ->toContain("'is_personal' => true")
        ->not->toContain('{{Organization}}');
});

it('generates workspace factory with afterCreating', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $factoryContent = file_get_contents(database_path('factories/WorkspaceFactory.php'));
    expect($factoryContent)
        ->toContain('afterCreating')
        ->toContain('->attach(')
        ->toContain("'role' => 'owner'");
});

it('generates workspace migrations with down method', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_workspaces_table.php'));
    expect($migrations)->not->toBeEmpty();
    $migrationContent = file_get_contents($migrations[0]);
    expect($migrationContent)
        ->toContain('public function down(): void')
        ->toContain("Schema::dropIfExists('workspaces')");
});

it('generates standalone workspace trait with owned relation', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    $traitContent = file_get_contents(app_path('Traits/BelongsToWorkspace.php'));
    expect($traitContent)
        ->toContain('function ownedWorkspaces(): HasMany')
        ->toContain("hasMany(Workspace::class, 'owner_id')");
});

// ──────────────────────────────────────────────────
// Fix 7: Workspace as Tenant Sub-Org (P4/P6)
// ──────────────────────────────────────────────────

it('generates tenant with workspaces substructure (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Workspace.php'))->toBeFile();
    expect(app_path('Traits/BelongsToWorkspace.php'))->toBeFile();

    $workspaceModel = file_get_contents(app_path('Models/Workspace.php'));
    expect($workspaceModel)
        ->toContain('use TenantScope;')
        ->toContain("'tenant_id'")
        ->not->toContain('owner_id')
        ->not->toContain('is_personal')
        ->not->toContain('{{organization}}');

    expect(database_path('migrations/0001_01_01_200002_create_workspaces_table.php'))->toBeFile();
    $migration = file_get_contents(database_path('migrations/0001_01_01_200002_create_workspaces_table.php'));
    expect($migration)
        ->toContain('tenant_id')
        ->toContain('constrained');

    expect(base_path('tests/Feature/WorkspaceTenantIsolationTest.php'))->toBeFile();
    $isolationTest = file_get_contents(base_path('tests/Feature/WorkspaceTenantIsolationTest.php'));
    expect($isolationTest)
        ->toContain('scopes workspace queries to the current tenant')
        ->not->toContain('{{organization}}');
});

it('generates tenant with workspaces substructure (multi-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'multi')
        ->expectsChoice('Optional substructure within each tenant?', ['workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    expect(app_path('Models/Workspace.php'))->toBeFile();
    expect(app_path('Traits/UsesTenantConnection.php'))->toBeFile();

    $workspaceModel = file_get_contents(app_path('Models/Workspace.php'));
    expect($workspaceModel)
        ->toContain('use UsesTenantConnection;')
        ->not->toContain('use TenantScope;')
        ->not->toContain("'tenant_id'")
        ->not->toContain('{{organization}}');

    expect(database_path('migrations/tenant/0001_01_01_000001_create_workspaces_table.php'))->toBeFile();
    $migration = file_get_contents(database_path('migrations/tenant/0001_01_01_000001_create_workspaces_table.php'));
    expect($migration)
        ->not->toContain('tenant_id')
        ->toContain("protected \$connection = 'tenant'");
});

// ──────────────────────────────────────────────────
// Fix 8: Both Sub-Orgs Content Verification
// ──────────────────────────────────────────────────

it('patches all files for tenant with both substructures (single-db)', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $userContent = file_get_contents(app_path('Models/User.php'));
    expect($userContent)
        ->toContain('use BelongsToTenant;')
        ->toContain('use BelongsToTeam;')
        ->toContain('use BelongsToWorkspace;');

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)
        ->toContain('NubosTenantServiceProvider::class')
        ->toContain('NubosTeamServiceProvider::class')
        ->toContain('NubosWorkspaceServiceProvider::class');

    $seederContent = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    expect($seederContent)
        ->toContain('TenantSeeder::class')
        ->toContain('TeamSeeder::class')
        ->toContain('WorkspaceSeeder::class');

    $inertiaContent = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($inertiaContent)
        ->toContain("'currentTenant'")
        ->toContain("'teams'")
        ->toContain("'currentTeam'")
        ->toContain("'workspaces'")
        ->toContain("'currentWorkspace'");

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)
        ->toContain('TeamSwitcher')
        ->toContain('WorkspaceSwitcher');
});

// ──────────────────────────────────────────────────
// Fix 9: TenantScope strict null check
// ──────────────────────────────────────────────────

it('uses strict null check in tenant scope creating callback', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $scopeContent = file_get_contents(app_path('Traits/TenantScope.php'));
    expect($scopeContent)
        ->toContain('$model->tenant_id === null')
        ->not->toContain('!$model->tenant_id');
});

// ──────────────────────────────────────────────────
// Fix 9: CreatePersonalOrganization simplification
// ──────────────────────────────────────────────────

it('creates personal organization in single step', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    $listenerContent = file_get_contents(app_path('Listeners/CreatePersonalTeam.php'));
    expect($listenerContent)
        ->toContain("'is_personal' => true")
        ->not->toContain("->update(['is_personal' => true])");

    $createAction = file_get_contents(app_path('Actions/Teams/CreateTeamAction.php'));
    expect($createAction)
        ->toContain("'is_personal' => \$data['is_personal'] ?? false");
});

// ──────────────────────────────────────────────────
// Critic Review P0-2: standalone test stubs
// ──────────────────────────────────────────────────

it('generates standalone test stubs for team', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->assertSuccessful();

    expect(base_path('tests/Feature/TeamMembershipTest.php'))->toBeFile();
    expect(base_path('tests/Feature/TeamPolicyTest.php'))->toBeFile();
    expect(base_path('tests/Feature/TeamCrudTest.php'))->toBeFile();
    expect(base_path('tests/Feature/SetCurrentTeamMiddlewareTest.php'))->toBeFile();

    $membershipTest = file_get_contents(base_path('tests/Feature/TeamMembershipTest.php'));
    expect($membershipTest)
        ->toContain('allows a user to join the team')
        ->toContain('allows a user to leave the team')
        ->toContain('belongsToTeam')
        ->toContain('ownsTeam')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');

    $policyTest = file_get_contents(base_path('tests/Feature/TeamPolicyTest.php'));
    expect($policyTest)
        ->toContain('allows the owner to update the team')
        ->toContain('prevents deletion of a personal team')
        ->toContain('prevents a non-member from viewing the team')
        ->toContain('allows any authenticated user to create the team')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');

    $crudTest = file_get_contents(base_path('tests/Feature/TeamCrudTest.php'));
    expect($crudTest)
        ->toContain('creates the team with correct owner and pivot entry')
        ->toContain('soft deletes the team')
        ->toContain('generates a unique slug')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');

    $middlewareTest = file_get_contents(base_path('tests/Feature/SetCurrentTeamMiddlewareTest.php'));
    expect($middlewareTest)
        ->toContain('sets current team in the container')
        ->toContain('throws 404 when team route parameter is invalid')
        ->toContain('throws 403 when user is not a member')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');
});

it('generates standalone test stubs for workspace', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'workspace')
        ->assertSuccessful();

    expect(base_path('tests/Feature/WorkspaceMembershipTest.php'))->toBeFile();
    expect(base_path('tests/Feature/WorkspacePolicyTest.php'))->toBeFile();
    expect(base_path('tests/Feature/WorkspaceCrudTest.php'))->toBeFile();
    expect(base_path('tests/Feature/SetCurrentWorkspaceMiddlewareTest.php'))->toBeFile();

    $membershipTest = file_get_contents(base_path('tests/Feature/WorkspaceMembershipTest.php'));
    expect($membershipTest)
        ->toContain('allows a user to join the workspace')
        ->toContain('belongsToWorkspace')
        ->not->toContain('{{organization}}')
        ->not->toContain('{{Organization}}');
});

// ──────────────────────────────────────────────────
// Critic Review P0-1: patchWebRoutes warning
// ──────────────────────────────────────────────────

it('warns when web routes cannot be extracted due to non-standard format', function (): void {
    backupOriginalFiles();

    file_put_contents(base_path('routes/web.php'), "<?php\n\nRoute::get('/', fn() => 'hello');\n");

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->expectsOutputToContain('Could not extract auth routes from routes/web.php')
        ->assertSuccessful();

    expect(base_path('routes/app.php'))->not->toBeFile();
});

// ──────────────────────────────────────────────────
// Critic Review P1-1: patchFile warning
// ──────────────────────────────────────────────────

it('warns when patchFile search string is not found', function (): void {
    backupOriginalFiles();

    $userPath = app_path('Models/User.php');
    file_put_contents($userPath, "<?php\n\nnamespace App\\Models;\n\nclass User {}\n");

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'team')
        ->expectsOutputToContain('Could not apply patch')
        ->assertSuccessful();
});

// ──────────────────────────────────────────────────
// Critic Review P1-2: copyOrganizationStubs warning
// ──────────────────────────────────────────────────

it('warns when stub directory is not found', function (): void {
    backupOriginalFiles();

    $missingStubDir = dirname(__DIR__) . '/stubs/organization/standalone';
    $backupDir = dirname(__DIR__) . '/stubs/organization/standalone.bak';

    rename($missingStubDir, $backupDir);

    try {
        $this->artisan('nubos:init')
            ->expectsQuestion('Which organization structure do you need?', 'team')
            ->expectsOutputToContain('Stub directory not found')
            ->assertSuccessful();
    } finally {
        rename($backupDir, $missingStubDir);
    }
});
