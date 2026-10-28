<?php

declare(strict_types=1);

use App\Actions\Dashboards\CreateDashboardAction;
use App\Actions\Dashboards\RevokeDashboardShareAction;
use App\Actions\Dashboards\SetDefaultDashboardAction;
use App\Actions\Dashboards\ShareDashboardAction;
use App\Actions\Dashboards\UpdateDashboardAction;
use App\Actions\Dashboards\UpdateDashboardLayoutAction;
use App\Enums\Authorization\RoleAuthority;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\Role;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->auditor = Mockery::mock(AdminArtifactAuditor::class);
    $this->auditor->shouldReceive('record')->byDefault();

    $this->plain = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'plain-actor');

    $this->escalated = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [
        ModelStub::make(Role::class, [
            'id' => ModelStub::ulid('scope-admin'),
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'scope-admin',
            'authority' => RoleAuthority::ScopeAdmin->value,
        ]),
    ], 'escalated-actor');

    $this->dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('guarded-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->plain->getKey(),
        'is_tenant_wide' => false,
        'name' => 'Board',
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('asks the gate for the create ability before it ever validates the payload', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): Dashboard => (new CreateDashboardAction($this->auditor))->execute($this->plain, []))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('create'))->toBeTrue();
});

it('refuses a caller without an elevated role who asks for a tenant wide dashboard', function (): void {
    GateSpy::allowing('create');

    $refusal = null;

    try {
        (new CreateDashboardAction($this->auditor))->execute($this->plain, [
            'name' => 'Everyone board',
            'is_tenant_wide' => true,
        ]);
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['is_tenant_wide']);
});

it('lets an elevated role create a tenant wide dashboard instead of refusing the flag', function (): void {
    GateSpy::allowing('create');

    expect(WriteAttempt::reachedTheDatabase(fn (): Dashboard => (new CreateDashboardAction($this->auditor))->execute(
        $this->escalated,
        ['name' => 'Everyone board', 'is_tenant_wide' => true],
    )))->toBeTrue();
});

it('never lets the payload smuggle the tenant or the owner of a new dashboard', function (): void {
    GateSpy::allowing('create');

    $refusal = null;

    try {
        (new CreateDashboardAction($this->auditor))->execute($this->plain, [
            'tenant_id' => ModelStub::ulid('other-tenant'),
            'owner_id' => ModelStub::ulid('victim'),
        ]);
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['name']);
});

it('asks the gate for the update ability before it touches the dashboard', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): Dashboard => (new UpdateDashboardAction($this->auditor))->execute($this->plain, $this->dashboard, []))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('update'))->toBeTrue();
});

it('refuses a plain owner who switches the tenant wide flag in either direction', function (): void {
    GateSpy::allowing('update');

    $switchOn = fn (): Dashboard => (new UpdateDashboardAction($this->auditor))
        ->execute($this->plain, $this->dashboard, ['is_tenant_wide' => true]);

    $wide = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('wide-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->plain->getKey(),
        'is_tenant_wide' => true,
        'name' => 'Board',
    ]);

    $switchOff = fn (): Dashboard => (new UpdateDashboardAction($this->auditor))
        ->execute($this->plain, $wide, ['is_tenant_wide' => false]);

    expect($switchOn)->toThrow(ValidationException::class)
        ->and($switchOff)->toThrow(ValidationException::class);
});

it('lets a plain owner resend the unchanged tenant wide flag without a refusal', function (): void {
    GateSpy::allowing('update');

    expect(WriteAttempt::reachedTheDatabase(fn (): Dashboard => (new UpdateDashboardAction($this->auditor))
        ->execute($this->plain, $this->dashboard, ['is_tenant_wide' => false, 'name' => 'Renamed'])))
        ->toBeTrue();
});

it('asks the gate for the share ability before it reads a single grantee', function (): void {
    $spy = GateSpy::allowing();

    expect(fn (): DashboardShare => (new ShareDashboardAction)->execute(
        $this->dashboard,
        ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('grantee')],
        $this->plain,
    ))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('share'))->toBeTrue();
});

it('refuses a grantee type that names no known holder and writes nothing', function (): void {
    GateSpy::allowing('share');

    $refusal = null;

    try {
        (new ShareDashboardAction)->execute(
            $this->dashboard,
            ['grantee_type' => 'dashboard', 'grantee_id' => ModelStub::ulid('grantee')],
            $this->plain,
        );
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['grantee_type']);
});

it('looks a user grantee up inside the bound tenant and never by identifier alone', function (): void {
    GateSpy::allowing('share');

    $granteeId = ModelStub::ulid('grantee');

    $shape = QueryShape::attemptedBy(fn (): DashboardShare => (new ShareDashboardAction)->execute(
        $this->dashboard,
        ['grantee_type' => 'user', 'grantee_id' => $granteeId],
        $this->plain,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('users', $granteeId))->toBeTrue();
});

it('looks a team grantee up inside the bound tenant as well', function (): void {
    GateSpy::allowing('share');

    $shape = QueryShape::attemptedBy(fn (): DashboardShare => (new ShareDashboardAction)->execute(
        $this->dashboard,
        ['grantee_type' => 'team', 'grantee_id' => ModelStub::ulid('team-grantee')],
        $this->plain,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('refuses every grantee outright while no tenant is bound and reaches no query at all', function (): void {
    GateSpy::allowing('share');
    AccessContext::forgetTenant();

    $shape = QueryShape::attemptedBy(function (): void {
        try {
            (new ShareDashboardAction)->execute(
                $this->dashboard,
                ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('grantee')],
                $this->plain,
            );
        } catch (ModelNotFoundException) {
            return;
        }
    });

    expect($shape)->toBeNull()
        ->and(fn (): DashboardShare => (new ShareDashboardAction)->execute(
            $this->dashboard,
            ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('grantee')],
            $this->plain,
        ))->toThrow(ModelNotFoundException::class);
});

it('asks the gate for the share ability of the parent dashboard before revoking a grant', function (): void {
    $spy = GateSpy::allowing();

    $share = ModelStub::make(DashboardShare::class, [
        'id' => ModelStub::ulid('revoked-share'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
        'grantee_type' => $this->plain->getMorphClass(),
        'grantee_id' => ModelStub::ulid('grantee'),
    ], ['dashboard' => $this->dashboard]);

    expect(fn () => (new RevokeDashboardShareAction)->execute($this->plain, $share))
        ->toThrow(AuthorizationException::class)
        ->and($spy->calls[0]['arguments'][0])->toBe($this->dashboard);
});

it('refuses to make a dashboard the start page of a caller who may not see it', function (): void {
    $spy = GateSpy::allowing();

    expect(fn () => (new SetDefaultDashboardAction)->execute($this->plain, $this->dashboard))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('view'))->toBeTrue();
});

it('asks the gate for the update ability before it rewrites the arrangement', function (): void {
    $spy = GateSpy::allowing();

    expect(fn () => (new UpdateDashboardLayoutAction)->execute($this->plain, $this->dashboard, [
        'widgets' => [['id' => ModelStub::ulid('widget'), 'column_span' => 1]],
    ]))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('update'))->toBeTrue();
});

it('refuses a column span outside the three steps and an arrangement without widgets', function (): void {
    GateSpy::allowing('update');

    $tooWide = fn () => (new UpdateDashboardLayoutAction)->execute($this->plain, $this->dashboard, [
        'widgets' => [['id' => ModelStub::ulid('widget'), 'column_span' => 4]],
    ]);

    $empty = fn () => (new UpdateDashboardLayoutAction)->execute($this->plain, $this->dashboard, []);

    expect($tooWide)->toThrow(ValidationException::class)
        ->and($empty)->toThrow(ValidationException::class);
});

it('reads the stored arrangement only within the dashboard it was asked to rearrange', function (): void {
    GateSpy::allowing('update');

    $shape = QueryShape::attemptedBy(fn () => (new UpdateDashboardLayoutAction)->execute(
        $this->plain,
        $this->dashboard,
        ['widgets' => [['id' => ModelStub::ulid('widget'), 'column_span' => 2]]],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('dashboard_widgets'))->toBeTrue()
        ->and($shape->sql)->toContain('"dashboard_id" = ?')
        ->and($shape->isScopedToTenant('dashboard_widgets', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->dashboard->getKey()))->toBeTrue();
});
