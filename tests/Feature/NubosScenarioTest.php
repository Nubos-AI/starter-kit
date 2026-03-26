<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

function scenarioCleanup(): void
{
    $paths = [
        config_path('nubos.php'),
        app_path('Models/Team.php'),
        app_path('Models/Workspace.php'),
        app_path('Models/Tenant.php'),
        app_path('Models/Domain.php'),
        app_path('Traits/Teams'),
        app_path('Traits/Workspaces'),
        app_path('Traits/Tenants'),
        app_path('Http/Middleware/SetCurrentTeam.php'),
        app_path('Http/Middleware/SetCurrentWorkspace.php'),
        app_path('Http/Middleware/RedirectToCurrentTeam.php'),
        app_path('Http/Middleware/RedirectToCurrentWorkspace.php'),
        app_path('Http/Middleware/RedirectToCurrentOrg.php'),
        app_path('Http/Middleware/TenantIdentification.php'),
        app_path('Http/Middleware/EnsureTenantMembership.php'),
        app_path('Queue/Middleware/TenantAwareJob.php'),
        app_path('Actions/Teams'),
        app_path('Actions/Workspaces'),
        app_path('Actions/Tenants'),
        app_path('Events/Teams'),
        app_path('Events/Workspaces'),
        app_path('Events/Tenants'),
        app_path('Jobs'),
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

function assertAppDirectoryClean(): void
{
    expect(file_exists(app_path('Traits/Users/PasswordValidationRules.php')))->toBeTrue(
        'PasswordValidationRules.php was deleted by scenario cleanup!'
    );
}

function initTeam(object $test): void
{
    $test->artisan('nubos:init')
        ->expectsChoice('Organization type?', 'Team', ['Team', 'Workspace', 'Tenant'])
        ->assertSuccessful();
}

function initWorkspace(object $test): void
{
    $test->artisan('nubos:init')
        ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
        ->expectsConfirmation('Enable teams within workspaces?', 'no')
        ->assertSuccessful();
}

function initWorkspaceTeams(object $test): void
{
    $test->artisan('nubos:init')
        ->expectsChoice('Organization type?', 'Workspace', ['Team', 'Workspace', 'Tenant'])
        ->expectsConfirmation('Enable teams within workspaces?', 'yes')
        ->assertSuccessful();
}

function initTenant(object $test, string $dbStrategy, string $subStructure): void
{
    $test->artisan('nubos:init')
        ->expectsChoice('Organization type?', 'Tenant', ['Team', 'Workspace', 'Tenant'])
        ->expectsChoice('Database strategy?', $dbStrategy, ['Single-Database', 'Multi-Database'])
        ->expectsChoice('Sub-structure within tenant?', $subStructure, ['None', 'Teams', 'Workspaces', 'Workspaces + Teams'])
        ->assertSuccessful();
}

function runGeneratedMigrations(): void
{
    Artisan::call('migrate');
}

function assertTablesExist(array $tables): void
{
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table '{$table}' should exist");
    }
}

function assertUserModelContains(string ...$strings): void
{
    $content = file_get_contents(app_path('Models/User.php'));
    foreach ($strings as $string) {
        expect($content)->toContain($string);
    }
}

function assertConfigContains(string ...$strings): void
{
    $content = file_get_contents(config_path('nubos.php'));
    foreach ($strings as $string) {
        expect($content)->toContain($string);
    }
}

beforeEach(function (): void {
    $this->originalWebRoutes = file_get_contents(base_path('routes/web.php'));
    $this->originalUserModel = file_get_contents(app_path('Models/User.php'));
    scenarioCleanup();
});

afterEach(function (): void {
    scenarioCleanup();
    file_put_contents(base_path('routes/web.php'), $this->originalWebRoutes);
    file_put_contents(app_path('Models/User.php'), $this->originalUserModel);
    assertAppDirectoryClean();
});

// ---------------------------------------------------------------------------
// Scenario 1: Team
// ---------------------------------------------------------------------------
describe('Scenario: Team', function (): void {
    beforeEach(function (): void {
        initTeam($this);
        runGeneratedMigrations();
    });

    it('generates team files', function (): void {
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Teams/CreateTeamAction.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/SetCurrentTeam.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/RedirectToCurrentTeam.php')))->toBeTrue();
        expect(file_exists(base_path('database/factories/TeamFactory.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'teams', 'team_user']);
    });

    it('adds HasTeams trait to User model', function (): void {
        assertUserModelContains('use App\Traits\Teams\HasTeams;', 'use HasTeams;');
    });

    it('writes correct config', function (): void {
        assertConfigContains("'organization_type' => 'team'", "'has_sub_teams' => false");
    });
});

// ---------------------------------------------------------------------------
// Scenario 2: Workspace
// ---------------------------------------------------------------------------
describe('Scenario: Workspace', function (): void {
    beforeEach(function (): void {
        initWorkspace($this);
        runGeneratedMigrations();
    });

    it('generates workspace files', function (): void {
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Workspaces/CreateWorkspaceAction.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/SetCurrentWorkspace.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'workspaces', 'workspace_user']);
    });

    it('adds HasWorkspaces trait to User model', function (): void {
        assertUserModelContains('use App\Traits\Workspaces\HasWorkspaces;', 'use HasWorkspaces;');
    });

    it('writes correct config', function (): void {
        assertConfigContains("'organization_type' => 'workspace'", "'has_sub_teams' => false");
    });
});

// ---------------------------------------------------------------------------
// Scenario 3: Workspace + Teams
// ---------------------------------------------------------------------------
describe('Scenario: Workspace + Teams', function (): void {
    beforeEach(function (): void {
        initWorkspaceTeams($this);
        runGeneratedMigrations();
    });

    it('generates workspace and team files', function (): void {
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'workspaces', 'workspace_user', 'teams', 'team_user']);
    });

    it('adds both traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Workspaces\HasWorkspaces;',
            'use App\Traits\Teams\HasTeams;',
            'use HasWorkspaces;',
            'use HasTeams;',
        );
    });

    it('writes correct config', function (): void {
        assertConfigContains("'organization_type' => 'workspace'", "'has_sub_teams' => true");
    });
});

// ---------------------------------------------------------------------------
// Scenario 4: Tenant Single-DB (no sub-orgs)
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Single-DB', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Single-Database', 'None');
        runGeneratedMigrations();
    });

    it('generates tenant files', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Domain.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/BelongsToTenant.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Tenants/CreateTenantAction.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Middleware/TenantIdentification.php')))->toBeTrue();
    });

    it('does not generate multi-db files', function (): void {
        expect(file_exists(app_path('Traits/Tenants/HasTenantDatabase.php')))->toBeFalse();
        expect(file_exists(app_path('Traits/Tenants/TenantAware.php')))->toBeFalse();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user']);
    });

    it('adds BelongsToTenant trait to User model', function (): void {
        assertUserModelContains('use App\Traits\Tenants\BelongsToTenant;', 'use BelongsToTenant;');
    });

    it('writes correct config', function (): void {
        assertConfigContains("'organization_type' => 'tenant'", "'database_strategy' => 'single'");
    });
});

// ---------------------------------------------------------------------------
// Scenario 5: Tenant Single-DB + Teams
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Single-DB + Teams', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Single-Database', 'Teams');
        runGeneratedMigrations();
    });

    it('generates tenant and team files', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/TenantScope.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'teams', 'team_user']);
    });

    it('team migration has tenant_id foreign key', function (): void {
        expect(Schema::hasColumn('teams', 'tenant_id'))->toBeTrue();
    });

    it('adds BelongsToTenant and HasTeams traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Teams\HasTeams;',
        );
    });

    it('team model has TenantScope trait', function (): void {
        $content = file_get_contents(app_path('Models/Team.php'));
        expect($content)->toContain('use App\Traits\Tenants\TenantScope;');
    });
});

// ---------------------------------------------------------------------------
// Scenario 6: Tenant Single-DB + Workspaces
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Single-DB + Workspaces', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Single-Database', 'Workspaces');
        runGeneratedMigrations();
    });

    it('generates tenant and workspace files', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/TenantScope.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'workspaces', 'workspace_user']);
    });

    it('workspace migration has tenant_id foreign key', function (): void {
        expect(Schema::hasColumn('workspaces', 'tenant_id'))->toBeTrue();
    });

    it('adds BelongsToTenant and HasWorkspaces traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Workspaces\HasWorkspaces;',
        );
    });
});

// ---------------------------------------------------------------------------
// Scenario 7: Tenant Single-DB + Workspaces + Teams
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Single-DB + Workspaces + Teams', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Single-Database', 'Workspaces + Teams');
        runGeneratedMigrations();
    });

    it('generates all files', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/TenantScope.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'workspaces', 'workspace_user', 'teams', 'team_user']);
    });

    it('adds all traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Workspaces\HasWorkspaces;',
            'use App\Traits\Teams\HasTeams;',
        );
    });

    it('writes correct config', function (): void {
        assertConfigContains("'organization_type' => 'tenant'", "'sub_organization' => 'workspace-teams'");
    });
});

// ---------------------------------------------------------------------------
// Scenario 8: Tenant Multi-DB (no sub-orgs)
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Multi-DB', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Multi-Database', 'None');
        runGeneratedMigrations();
    });

    it('generates tenant files with multi-db support', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/HasTenantDatabase.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/TenantAware.php')))->toBeTrue();
        expect(file_exists(app_path('Actions/Tenants/ConfigureTenantDatabaseAction.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user']);
    });

    it('tenant migration has db fields', function (): void {
        $migrationFiles = glob(base_path('database/migrations/*_create_tenants_table.php'));
        $content = file_get_contents($migrationFiles[0]);
        expect($content)->toContain('db_host')
            ->and($content)->toContain('db_database')
            ->and($content)->toContain('db_password');
    });

    it('tenant model has db fields and HasTenantDatabase trait', function (): void {
        $content = file_get_contents(app_path('Models/Tenant.php'));
        expect($content)->toContain('use App\Traits\Tenants\HasTenantDatabase;')
            ->and($content)->toContain("'db_password' => 'encrypted'");
    });

    it('writes correct config', function (): void {
        assertConfigContains("'database_strategy' => 'multi'");
    });
});

// ---------------------------------------------------------------------------
// Scenario 9: Tenant Multi-DB + Teams
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Multi-DB + Teams', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Multi-Database', 'Teams');
        runGeneratedMigrations();
    });

    it('generates tenant and team files with multi-db', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/HasTenantDatabase.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'teams', 'team_user']);
    });

    it('tenant migration has db fields and team has tenant_id', function (): void {
        $migrationFiles = glob(base_path('database/migrations/*_create_tenants_table.php'));
        $content = file_get_contents($migrationFiles[0]);
        expect($content)->toContain('db_host');
        expect(Schema::hasColumn('teams', 'tenant_id'))->toBeTrue();
    });

    it('adds all required traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Teams\HasTeams;',
        );
    });
});

// ---------------------------------------------------------------------------
// Scenario 10: Tenant Multi-DB + Workspaces
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Multi-DB + Workspaces', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Multi-Database', 'Workspaces');
        runGeneratedMigrations();
    });

    it('generates tenant and workspace files with multi-db', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/HasTenantDatabase.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'workspaces', 'workspace_user']);
    });

    it('tenant migration has db fields and workspace has tenant_id', function (): void {
        $migrationFiles = glob(base_path('database/migrations/*_create_tenants_table.php'));
        $content = file_get_contents($migrationFiles[0]);
        expect($content)->toContain('db_host');
        expect(Schema::hasColumn('workspaces', 'tenant_id'))->toBeTrue();
    });

    it('adds all required traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Workspaces\HasWorkspaces;',
        );
    });
});

// ---------------------------------------------------------------------------
// Scenario 11: Tenant Multi-DB + Workspaces + Teams
// ---------------------------------------------------------------------------
describe('Scenario: Tenant Multi-DB + Workspaces + Teams', function (): void {
    beforeEach(function (): void {
        initTenant($this, 'Multi-Database', 'Workspaces + Teams');
        runGeneratedMigrations();
    });

    it('generates all files with multi-db', function (): void {
        expect(file_exists(app_path('Models/Tenant.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Workspace.php')))->toBeTrue();
        expect(file_exists(app_path('Models/Team.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/HasTenantDatabase.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Tenants/TenantAware.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Workspaces/HasWorkspaces.php')))->toBeTrue();
        expect(file_exists(app_path('Traits/Teams/HasTeams.php')))->toBeTrue();
    });

    it('creates correct database tables', function (): void {
        assertTablesExist(['users', 'tenants', 'domains', 'tenant_user', 'workspaces', 'workspace_user', 'teams', 'team_user']);
    });

    it('tenant migration has db fields, workspace and team have tenant_id', function (): void {
        $migrationFiles = glob(base_path('database/migrations/*_create_tenants_table.php'));
        $content = file_get_contents($migrationFiles[0]);
        expect($content)->toContain('db_host');
        expect(Schema::hasColumn('workspaces', 'tenant_id'))->toBeTrue();
        expect(Schema::hasColumn('teams', 'tenant_id'))->toBeTrue();
    });

    it('adds all traits to User model', function (): void {
        assertUserModelContains(
            'use App\Traits\Tenants\BelongsToTenant;',
            'use App\Traits\Workspaces\HasWorkspaces;',
            'use App\Traits\Teams\HasTeams;',
        );
    });

    it('writes correct config', function (): void {
        assertConfigContains(
            "'organization_type' => 'tenant'",
            "'database_strategy' => 'multi'",
            "'sub_organization' => 'workspace-teams'",
        );
    });

    it('no injection markers remain in any generated file', function (): void {
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
                expect($file->getContents())->not->toContain('@nubos:inject');
            }
        }
    });
});
