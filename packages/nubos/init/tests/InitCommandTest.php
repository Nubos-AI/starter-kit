<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    cleanGeneratedFiles();
});

afterEach(function (): void {
    cleanGeneratedFiles();
});

function cleanGeneratedFiles(): void
{
    $paths = [
        config_path('nubos.php'),
        app_path('Models/Team.php'),
        app_path('Models/Workspace.php'),
        app_path('Models/Tenant.php'),
        app_path('Models/Domain.php'),
        app_path('Traits'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Middleware/SetCurrentWorkspace.php'),
        app_path('Http/Middleware/RedirectToCurrentTeam.php'),
        app_path('Http/Middleware/RedirectToCurrentWorkspace.php'),
        app_path('Http/Middleware/RedirectToCurrentOrg.php'),
        app_path('Http/Middleware/TenantIdentification.php'),
        app_path('Queue/Middleware/TenantAwareJob.php'),
        app_path('Actions/Teams'),
        app_path('Actions/Workspaces'),
        app_path('Actions/Tenants'),
        app_path('Events/Teams'),
        app_path('Events/Workspaces'),
        app_path('Events/Tenants'),
        app_path('Providers/NubosOrganizationServiceProvider.php'),
        app_path('Providers/NubosTenantServiceProvider.php'),
        base_path('database/seeders/NubosSeeder.php'),
        base_path('database/factories/TeamFactory.php'),
        base_path('database/factories/WorkspaceFactory.php'),
        base_path('database/factories/TenantFactory.php'),
        base_path('database/factories/DomainFactory.php'),
        base_path('tests/Feature/TeamMiddlewareTest.php'),
        base_path('tests/Feature/TeamActionTest.php'),
        base_path('tests/Feature/TeamModelTest.php'),
        base_path('tests/Feature/WorkspaceMiddlewareTest.php'),
        base_path('tests/Feature/WorkspaceActionTest.php'),
        base_path('tests/Feature/WorkspaceModelTest.php'),
        base_path('tests/Feature/TenantActionTest.php'),
        base_path('tests/Feature/TenantIdentificationTest.php'),
        base_path('tests/Feature/TenantIsolationTest.php'),
        base_path('tests/Feature/TenantMultiDbTest.php'),
        base_path('tests/Feature/WorkspaceTeamHierarchyTest.php'),
        base_path('routes/team.php'),
        base_path('routes/workspace.php'),
        base_path('routes/tenant.php'),
    ];

    $filesystem = new Filesystem();

    foreach ($paths as $path) {
        if ($filesystem->isDirectory($path)) {
            $filesystem->deleteDirectory($path);
        } elseif ($filesystem->exists($path)) {
            $filesystem->delete($path);
        }
    }

    $migrationPath = base_path('database/migrations');
    if ($filesystem->isDirectory($migrationPath)) {
        foreach ($filesystem->files($migrationPath) as $file) {
            if (preg_match('/_(11|12)\d{4}_/', $file->getFilename())) {
                $filesystem->delete($file->getPathname());
            }
        }
    }
}

describe('Guard', function (): void {
    it('aborts if nubos is already initialized', function (): void {
        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists(config_path());
        file_put_contents(config_path('nubos.php'), "<?php\nreturn ['organization_type' => 'team'];");

        $this->artisan('nubos:init')
            ->assertFailed();
    });
});

describe('P1: Team', function (): void {
    it('generates team files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
            ->assertSuccessful();

        expect(file_exists(config_path('nubos.php')))->toBeTrue();
        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'organization_type' => 'team'");
        expect($configContent)->toContain('\\App\\Models\\Team::class');
        expect($configContent)->toContain("'has_sub_teams' => false");

        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        $modelContent = file_get_contents(app_path('Models/Team.php'));
        expect($modelContent)->toContain('class Team extends Model');
        expect($modelContent)->toContain('use HasUuids;');
        expect($modelContent)->toContain('use SoftDeletes;');
        expect($modelContent)->toContain("'personal_team'");
        expect($modelContent)->not->toContain('@nubos:inject');

        expect(file_exists(base_path('database/migrations/0001_01_01_110000_create_teams_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110001_create_team_user_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110002_add_current_team_id_to_users_table.php')))->toBeTrue();

        $migrationContent = file_get_contents(base_path('database/migrations/0001_01_01_110000_create_teams_table.php'));
        expect($migrationContent)->toContain("Schema::create('teams'");
        expect($migrationContent)->not->toContain('@nubos:inject');
        expect($migrationContent)->not->toContain('tenant_id');

        expect(file_exists(app_path('Traits/HasTeams.php')))->toBeTrue();
        $traitContent = file_get_contents(app_path('Traits/HasTeams.php'));
        expect($traitContent)->toContain('trait HasTeams');
        expect($traitContent)->toContain('currentTeam()');

        expect(file_exists(app_path('Http/Middleware/SetCurrentTeam.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/RedirectToCurrentTeam.php')))->toBeTrue();

        expect(file_exists(app_path('Actions/Teams/CreateTeamAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Teams/AddTeamMemberAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Teams/RemoveTeamMemberAction.php')))->toBeTrue();

        expect(file_exists(app_path('Events/Teams/TeamCreated.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Teams/TeamMemberAdded.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Teams/TeamMemberRemoved.php')))->toBeTrue();

        expect(file_exists(base_path('routes/team.php')))->toBeTrue();

        expect(file_exists(app_path('Providers/NubosOrganizationServiceProvider.php')))->toBeTrue();

        expect(file_exists(base_path('database/seeders/NubosSeeder.php')))->toBeTrue();
        $seederContent = file_get_contents(base_path('database/seeders/NubosSeeder.php'));
        expect($seederContent)->toContain('CreateTeamAction');

        $providerContent = file_get_contents(app_path('Providers/NubosOrganizationServiceProvider.php'));
        expect($providerContent)->toContain("loadRoutesFrom(base_path('routes/team.php'))");

        expect(file_exists(base_path('database/factories/TeamFactory.php')))->toBeTrue();
        $factoryContent = file_get_contents(base_path('database/factories/TeamFactory.php'));
        expect($factoryContent)->toContain('class TeamFactory extends Factory');

        expect(file_exists(base_path('tests/Feature/TeamMiddlewareTest.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/TeamActionTest.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/TeamModelTest.php')))->toBeTrue();

        $pivotMigration = file_get_contents(base_path('database/migrations/0001_01_01_110001_create_team_user_table.php'));
        expect($pivotMigration)->toContain('softDeletes()');
    });

    it('does not generate workspace or tenant files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
            ->assertSuccessful();

        expect(file_exists(app_path('Models/Workspace.php')))->toBeFalse();
        expect(file_exists(app_path('Models/Tenant.php')))->toBeFalse();
        expect(file_exists(app_path('Models/Domain.php')))->toBeFalse();
    });

    it('replaces all placeholders in generated files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
            ->assertSuccessful();

        $files = [
            app_path('Models/Team.php'),
            app_path('Traits/HasTeams.php'),
            app_path('Http/Middleware/SetCurrentTeam.php'),
            app_path('Actions/Teams/CreateTeamAction.php'),
            app_path('Events/Teams/TeamCreated.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toContain('{{Model}}');
            expect($content)->not->toContain('{{model}}');
            expect($content)->not->toContain('{{models}}');
            expect($content)->not->toContain('{{Models}}');
        }
    });
});

describe('P2: Workspace (without Teams)', function (): void {
    it('generates workspace files with correct naming', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
            ->expectsConfirmation('Enable teams within workspaces?', 'no')
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'organization_type' => 'workspace'");
        expect($configContent)->toContain("'has_sub_teams' => false");

        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        $modelContent = file_get_contents(app_path('Models/Workspace.php'));
        expect($modelContent)->toContain('class Workspace extends Model');
        expect($modelContent)->toContain("'personal_workspace'");

        expect(file_exists(app_path('Models/Team.php')))->toBeFalse();

        expect(file_exists(base_path('database/migrations/0001_01_01_110000_create_workspaces_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110001_create_workspace_user_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110002_add_current_workspace_id_to_users_table.php')))->toBeTrue();

        expect(file_exists(app_path('Http/Middleware/SetCurrentWorkspace.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/RedirectToCurrentWorkspace.php')))->toBeTrue();

        expect(file_exists(app_path('Actions/Workspaces/CreateWorkspaceAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Workspaces/AddWorkspaceMemberAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Workspaces/RemoveWorkspaceMemberAction.php')))->toBeTrue();

        expect(file_exists(app_path('Traits/HasWorkspaces.php')))->toBeTrue();

        expect(file_exists(base_path('routes/workspace.php')))->toBeTrue();

        expect(file_exists(app_path('Providers/NubosOrganizationServiceProvider.php')))->toBeTrue();

        expect(file_exists(base_path('database/seeders/NubosSeeder.php')))->toBeTrue();
        $seederContent = file_get_contents(base_path('database/seeders/NubosSeeder.php'));
        expect($seederContent)->toContain('CreateWorkspaceAction');
    });

    it('replaces all placeholders in generated workspace files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
            ->expectsConfirmation('Enable teams within workspaces?', 'no')
            ->assertSuccessful();

        $files = [
            app_path('Models/Workspace.php'),
            app_path('Traits/HasWorkspaces.php'),
            app_path('Http/Middleware/SetCurrentWorkspace.php'),
            app_path('Actions/Workspaces/CreateWorkspaceAction.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->not->toContain('{{Model}}');
            expect($content)->not->toContain('{{model}}');
            expect($content)->not->toContain('{{models}}');
            expect($content)->not->toContain('{{Models}}');
        }
    });
});

describe('P3: Workspace + Teams', function (): void {
    it('generates workspace and team files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
            ->expectsConfirmation('Enable teams within workspaces?', 'yes')
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'organization_type' => 'workspace'");
        expect($configContent)->toContain("'has_sub_teams' => true");

        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();

        $teamModel = file_get_contents(app_path('Models/Team.php'));
        expect($teamModel)->toContain('workspace()');
        expect($teamModel)->toContain('belongsTo(Workspace::class)');

        expect(file_exists(base_path('database/migrations/0001_01_01_110000_create_workspaces_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110001_create_workspace_user_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110002_create_teams_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110003_create_team_user_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110004_add_current_workspace_id_to_users_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_110005_add_current_team_id_to_users_table.php')))->toBeTrue();

        expect(file_exists(app_path('Traits/HasWorkspaces.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/HasTeams.php')))->toBeTrue();

        $middleware = file_get_contents(app_path('Http/Middleware/SetCurrentTeam.php'));
        expect($middleware)->toContain('current_workspace');

        $route = file_get_contents(base_path('routes/team.php'));
        expect($route)->toContain('workspaces/{workspace}/teams/{team}');

        expect(file_exists(app_path('Http/Middleware/SetCurrentWorkspace.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/SetCurrentTeam.php')))->toBeTrue();

        expect(file_exists(app_path('Actions/Teams/CreateTeamAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Teams/AddTeamMemberAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Teams/RemoveTeamMemberAction.php')))->toBeTrue();

        expect(file_exists(app_path('Events/Teams/TeamCreated.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Teams/TeamMemberAdded.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Teams/TeamMemberRemoved.php')))->toBeTrue();

        expect(file_exists(app_path('Http/Middleware/RedirectToCurrentOrg.php')))->toBeTrue();

        expect(file_exists(base_path('database/factories/TeamFactory.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/TeamActionTest.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/WorkspaceTeamHierarchyTest.php')))->toBeTrue();

        $seederContent = file_get_contents(base_path('database/seeders/NubosSeeder.php'));
        expect($seederContent)->toContain('CreateTeamAction');
        expect($seederContent)->toContain('AddTeamMemberAction');
        expect($seederContent)->not->toContain('Team::query()->create');

        $providerContent = file_get_contents(app_path('Providers/NubosOrganizationServiceProvider.php'));
        expect($providerContent)->toContain("loadRoutesFrom(base_path('routes/workspace.php'))");
        expect($providerContent)->toContain("loadRoutesFrom(base_path('routes/team.php'))");
    });

    it('enforces middleware order in routes', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
            ->expectsConfirmation('Enable teams within workspaces?', 'yes')
            ->assertSuccessful();

        $route = file_get_contents(base_path('routes/team.php'));

        preg_match('/middleware\(\[.*?\]\)/s', $route, $matches);
        $middlewareLine = $matches[0];

        $workspacePos = strpos($middlewareLine, 'SetCurrentWorkspace');
        $teamPos = strpos($middlewareLine, 'SetCurrentTeam');

        expect($workspacePos)->toBeLessThan($teamPos);
    });
});

describe('P4: Tenant Single-DB (no Sub-Orgs)', function (): void {
    it('generates tenant files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Single-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'None', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'organization_type' => 'tenant'");
        expect($configContent)->toContain("'database_strategy' => 'single'");
        expect($configContent)->toContain("'sub_organization' => null");

        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Domain.php')))->toBeTrue();

        $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
        expect($tenantModel)->not->toContain('HasTenantDatabase');
        expect($tenantModel)->not->toContain('@nubos:inject');

        expect(file_exists(base_path('database/migrations/0001_01_01_120000_create_tenants_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_120001_create_domains_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_120002_create_tenant_user_table.php')))->toBeTrue();

        $migration = file_get_contents(base_path('database/migrations/0001_01_01_120000_create_tenants_table.php'));
        expect($migration)->not->toContain('db_host');
        expect($migration)->not->toContain('db_password');

        expect(file_exists(app_path('Traits/TenantScope.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/BelongsToTenant.php')))->toBeTrue();

        expect(file_exists(app_path('Http/Middleware/TenantIdentification.php')))->toBeTrue();

        expect(file_exists(app_path('Actions/Tenants/CreateTenantAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Tenants/AddTenantMemberAction.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Tenants/RemoveTenantMemberAction.php')))->toBeTrue();

        expect(file_exists(app_path('Events/Tenants/TenantCreated.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Tenants/TenantMemberAdded.php')))->toBeTrue();
        expect(file_exists(app_path('Events/Tenants/TenantMemberRemoved.php')))->toBeTrue();

        expect(file_exists(base_path('routes/tenant.php')))->toBeTrue();

        expect(file_exists(app_path('Models/Team.php')))->toBeFalse();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeFalse();

        expect(file_exists(app_path('Traits/HasTenantDatabase.php')))->toBeFalse();
        expect(file_exists(app_path('Traits/TenantAware.php')))->toBeFalse();
        expect(file_exists(app_path('Actions/Tenants/ConfigureTenantDatabaseAction.php')))->toBeFalse();
        expect(file_exists(app_path('Queue/Middleware/TenantAwareJob.php')))->toBeFalse();

        $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
        expect($tenantModel)->toContain('protected function casts(): array');
        expect($tenantModel)->toContain('protected $hidden = [];');

        $providerContent = file_get_contents(app_path('Providers/NubosTenantServiceProvider.php'));
        expect($providerContent)->toContain("loadRoutesFrom(base_path('routes/tenant.php'))");

        expect(file_exists(base_path('database/factories/TenantFactory.php')))->toBeTrue();
        expect(file_exists(base_path('database/factories/DomainFactory.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/TenantActionTest.php')))->toBeTrue();
        expect(file_exists(base_path('tests/Feature/TenantIdentificationTest.php')))->toBeTrue();

        $pivotMigration = file_get_contents(base_path('database/migrations/0001_01_01_120002_create_tenant_user_table.php'));
        expect($pivotMigration)->toContain('softDeletes()');

        $createAction = file_get_contents(app_path('Actions/Tenants/CreateTenantAction.php'));
        expect($createAction)->not->toContain('ConfigureTenantDatabaseAction');
        expect($createAction)->not->toContain('@nubos:multi-db');
    });
});

describe('P5: Tenant Single-DB + Teams Sub-Org', function (): void {
    it('generates tenant and team files with tenant scoping', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Single-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'Teams', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'sub_organization' => 'team'");

        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();

        $teamModel = file_get_contents(app_path('Models/Team.php'));
        expect($teamModel)->toContain('use TenantScope;');
        expect($teamModel)->toContain('use App\Traits\TenantScope;');
        expect($teamModel)->not->toContain('@nubos:inject');

        expect(file_exists(base_path('database/migrations/0001_01_01_120003_create_teams_table.php')))->toBeTrue();
        $migration = file_get_contents(base_path('database/migrations/0001_01_01_120003_create_teams_table.php'));
        expect($migration)->toContain('tenant_id');
        expect($migration)->not->toContain('@nubos:inject');

        expect(file_exists(base_path('database/migrations/0001_01_01_120004_create_team_user_table.php')))->toBeTrue();
        expect(file_exists(base_path('database/migrations/0001_01_01_120005_add_current_team_id_to_users_table.php')))->toBeTrue();

        expect(file_exists(base_path('database/migrations/0001_01_01_110000_create_teams_table.php')))->toBeFalse();

        $routeContent = file_get_contents(base_path('routes/team.php'));
        expect($routeContent)->toContain('TenantIdentification::class');

        $migration = file_get_contents(base_path('database/migrations/0001_01_01_120003_create_teams_table.php'));
        expect($migration)->not->toContain("'slug')->unique()");
        expect($migration)->toContain("unique(['tenant_id', 'slug'])");
    });
});

describe('P6: Tenant Multi-DB (no Sub-Orgs)', function (): void {
    it('generates tenant files with multi-db fields and trait', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Multi-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'None', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'database_strategy' => 'multi'");
        expect($configContent)->toContain("'sub_organization' => null");

        $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
        expect($tenantModel)->toContain('use HasTenantDatabase;');
        expect($tenantModel)->toContain('use App\Traits\HasTenantDatabase;');

        $migration = file_get_contents(base_path('database/migrations/0001_01_01_120000_create_tenants_table.php'));
        expect($migration)->toContain('db_host');
        expect($migration)->toContain('db_port');
        expect($migration)->toContain('db_database');
        expect($migration)->toContain('db_username');
        expect($migration)->toContain('db_password');

        expect(file_exists(app_path('Traits/HasTenantDatabase.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/TenantAware.php')))->toBeTrue();
        expect(file_exists(app_path('Queue/Middleware/TenantAwareJob.php')))->toBeTrue();

        expect(file_exists(app_path('Actions/Tenants/ConfigureTenantDatabaseAction.php')))->toBeTrue();

        $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
        expect($tenantModel)->toContain("'db_host'");
        expect($tenantModel)->toContain("'db_password'");
        expect($tenantModel)->toContain("'db_password' => 'encrypted'");
        expect($tenantModel)->toContain("protected \$hidden");

        $middleware = file_get_contents(app_path('Http/Middleware/TenantIdentification.php'));
        expect($middleware)->toContain("config('nubos.database_strategy') === 'multi'");
        expect($middleware)->toContain('configureDatabaseConnection()');

        $createAction = file_get_contents(app_path('Actions/Tenants/CreateTenantAction.php'));
        expect($createAction)->toContain('validateSubdomain');
        expect($createAction)->toContain('RESERVED_SUBDOMAINS');
        expect($createAction)->toContain('ConfigureTenantDatabaseAction');
    });
});

describe('P7: Tenant Multi-DB + Workspaces + Teams', function (): void {
    it('generates all files with full combination', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Multi-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'Workspaces + Teams', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $configContent = file_get_contents(config_path('nubos.php'));
        expect($configContent)->toContain("'database_strategy' => 'multi'");
        expect($configContent)->toContain("'sub_organization' => 'workspace-teams'");

        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Domain.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();

        $tenantModel = file_get_contents(app_path('Models/Tenant.php'));
        expect($tenantModel)->toContain('use HasTenantDatabase;');

        $workspaceModel = file_get_contents(app_path('Models/Workspace.php'));
        expect($workspaceModel)->toContain('use TenantScope;');

        $teamModel = file_get_contents(app_path('Models/Team.php'));
        expect($teamModel)->toContain('use TenantScope;');

        expect(file_exists(base_path('database/migrations/0001_01_01_120003_create_workspaces_table.php')))->toBeTrue();
        $wsMigration = file_get_contents(base_path('database/migrations/0001_01_01_120003_create_workspaces_table.php'));
        expect($wsMigration)->toContain('tenant_id');

        expect(file_exists(base_path('database/migrations/0001_01_01_120006_create_teams_table.php')))->toBeTrue();
        $teamMigration = file_get_contents(base_path('database/migrations/0001_01_01_120006_create_teams_table.php'));
        expect($teamMigration)->toContain('tenant_id');

        $tenantMigration = file_get_contents(base_path('database/migrations/0001_01_01_120000_create_tenants_table.php'));
        expect($tenantMigration)->toContain('db_host');

        $wsRoute = file_get_contents(base_path('routes/workspace.php'));
        expect($wsRoute)->toContain('TenantIdentification::class');

        $teamRoute = file_get_contents(base_path('routes/team.php'));
        expect($teamRoute)->toContain('TenantIdentification::class');

        $wsMigration = file_get_contents(base_path('database/migrations/0001_01_01_120003_create_workspaces_table.php'));
        expect($wsMigration)->not->toContain("'slug')->unique()");
        expect($wsMigration)->toContain("unique(['tenant_id', 'slug'])");
    });

    it('does not leave original migration numbers for remapped files', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Multi-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'Workspaces + Teams', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        expect(file_exists(base_path('database/migrations/0001_01_01_110000_create_workspaces_table.php')))->toBeFalse();
        expect(file_exists(base_path('database/migrations/0001_01_01_110002_create_teams_table.php')))->toBeFalse();
    });
});

describe('Generated code quality', function (): void {
    it('all generated files have declare strict_types', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
            ->assertSuccessful();

        $phpFiles = [
            app_path('Models/Team.php'),
            app_path('Traits/HasTeams.php'),
            app_path('Http/Middleware/SetCurrentTeam.php'),
            app_path('Actions/Teams/CreateTeamAction.php'),
            app_path('Events/Teams/TeamCreated.php'),
        ];

        foreach ($phpFiles as $file) {
            expect(file_get_contents($file))->toContain('declare(strict_types=1)');
        }
    });

    it('no injection markers remain in generated files for team path', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
            ->assertSuccessful();

        $filesystem = new Filesystem();
        $paths = [
            app_path('Models'),
            app_path('Traits'),
            app_path('Actions'),
            app_path('Events'),
            app_path('Http/Middleware'),
        ];

        foreach ($paths as $path) {
            if (! $filesystem->isDirectory($path)) {
                continue;
            }
            foreach ($filesystem->allFiles($path) as $file) {
                $content = $file->getContents();
                expect($content)->not->toContain('@nubos:inject');
            }
        }
    });

    it('no injection markers remain in generated files for full tenant path', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Multi-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'Workspaces + Teams', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $filesystem = new Filesystem();
        $paths = [
            app_path('Models'),
            app_path('Traits'),
            app_path('Actions'),
            app_path('Events'),
            app_path('Http/Middleware'),
            app_path('Providers'),
        ];

        foreach ($paths as $path) {
            if (! $filesystem->isDirectory($path)) {
                continue;
            }
            foreach ($filesystem->allFiles($path) as $file) {
                $content = $file->getContents();
                expect($content)->not->toContain('@nubos:inject');
            }
        }
    });

    it('no injection markers remain in generated migrations', function (): void {
        $this->artisan('nubos:init')
            ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
            ->expectsChoice('Database strategy?', 'Multi-Database', ['Single-Database', 'Multi-Database'])
            ->expectsChoice('Sub-structure within tenant?', 'Workspaces + Teams', ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
            ->assertSuccessful();

        $filesystem = new Filesystem();
        $migrationPath = base_path('database/migrations');

        if ($filesystem->isDirectory($migrationPath)) {
            foreach ($filesystem->files($migrationPath) as $file) {
                if (preg_match('/_(11|12)\d{4}_/', $file->getFilename())) {
                    $content = file_get_contents($file->getPathname());
                    expect($content)->not->toContain('@nubos:inject');
                }
            }
        }
    });
});
