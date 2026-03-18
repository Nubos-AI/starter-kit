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
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Middleware/SetCurrentWorkspace.php'),
        app_path('Http/Middleware/IdentifyTenant.php'),
        app_path('Policies/TeamPolicy.php'),
        app_path('Policies/WorkspacePolicy.php'),
        app_path('Policies/TenantPolicy.php'),
        app_path('Providers/NubosTeamServiceProvider.php'),
        app_path('Providers/NubosWorkspaceServiceProvider.php'),
        app_path('Providers/NubosTenantServiceProvider.php'),
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
        base_path('tests/Feature/TeamTenantIsolationTest.php'),
        base_path('tests/Feature/WorkspaceTenantIsolationTest.php'),
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
        '*_create_tenant_user_table.php',
    ];

    foreach ($migrationPatterns as $pattern) {
        foreach (glob(database_path("migrations/{$pattern}")) ?: [] as $file) {
            unlink($file);
        }
    }

    foreach (['Teams', 'Workspaces', 'Tenants'] as $orgDir) {
        foreach ([app_path("Http/Controllers/{$orgDir}"), app_path("Actions/{$orgDir}")] as $dir) {
            if (is_dir($dir)) {
                foreach (glob("{$dir}/*.php") ?: [] as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
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
        ->expectsQuestion('Enable subdomains per tenant?', true)
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
        ->toContain('continues without tenant');
});

it('generates tenant model with correct structure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', true)
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $middleware = file_get_contents(app_path('Http/Middleware/IdentifyTenant.php'));
    expect($middleware)
        ->toContain('class IdentifyTenant')
        ->toContain('resolveFromSubdomain')
        ->toContain("app()->instance('currentTenant'")
        ->not->toContain('NotFoundHttpException')
        ->not->toContain('use App\Models\Domain')
        ->toContain("config('app.domain')");
});

it('patches user model with tenant trait', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', false)
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $providersContent = file_get_contents(base_path('bootstrap/providers.php'));
    expect($providersContent)->toContain('NubosTenantServiceProvider::class');

    $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
    expect($providerContent)
        ->toContain('IdentifyTenant::class')
        ->toContain('identify-tenant')
        ->toContain('prependMiddlewareToGroup')
        ->toContain('routes/tenant.php');
});

it('patches database seeder to call tenant seeder', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', false)
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $sidebarContent = file_get_contents(resource_path('js/components/AppSidebar.vue'));
    expect($sidebarContent)
        ->not->toContain('TenantSwitcher')
        ->not->toContain('TeamSwitcher');
});

it('does not extract web routes for tenant without substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', false)
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $webContent = file_get_contents(base_path('routes/web.php'));
    expect($webContent)->toContain("Route::inertia('dashboard'");
    expect(base_path('routes/app.php'))->not->toBeFile();
});

it('generates tenant with teams substructure', function (): void {
    backupOriginalFiles();

    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', true)
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
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', false)
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
        ->expectsQuestion('Enable subdomains per tenant?', true)
        ->expectsChoice('Optional substructure within each tenant?', ['teams', 'workspaces'], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $config = include config_path('nubos.php');

    expect($config)
        ->organization->toBe('tenant')
        ->database_strategy->toBe('multi')
        ->subdomains->toBeTrue()
        ->tenant_substructure->toBe(['teams', 'workspaces']);

    expect(app_path('Models/Team.php'))->toBeFile();
    expect(app_path('Models/Workspace.php'))->toBeFile();
    expect(app_path('Traits/BelongsToTeam.php'))->toBeFile();
    expect(app_path('Traits/BelongsToWorkspace.php'))->toBeFile();
});

it('stores subdomains config correctly when disabled', function (): void {
    $this->artisan('nubos:init')
        ->expectsQuestion('Which organization structure do you need?', 'tenant')
        ->expectsQuestion('Database strategy?', 'single')
        ->expectsQuestion('Enable subdomains per tenant?', false)
        ->expectsChoice('Optional substructure within each tenant?', [], ['Teams within tenant', 'Workspaces within tenant', 'teams', 'workspaces'])
        ->assertSuccessful();

    $config = include config_path('nubos.php');

    expect($config)
        ->subdomains->toBeFalse();
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
