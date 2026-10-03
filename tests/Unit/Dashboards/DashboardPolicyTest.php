<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\DashboardWidget;
use App\Models\Role;
use App\Models\Team;
use App\Policies\Dashboards\DashboardPolicy;
use App\Policies\Dashboards\DashboardWidgetPolicy;
use App\Support\Authorization\TenantBoundary;
use App\Support\Sharing\ShareGranteeMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->policy = new DashboardPolicy(new TenantBoundary, new ShareGranteeMatcher);

    $this->owner = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'dashboard-owner');

    /** @var callable(string, list<Role>, list<Team>):RoleHolder */
    $this->member = function (string $seed, array $roles = [], array $teams = []): RoleHolder {
        $member = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], $roles, $seed);
        $member->setRelation('teams', new EloquentCollection($teams));

        return $member;
    };

    /** @var callable(array<string, mixed>, list<DashboardShare>):Dashboard */
    $this->dashboard = function (array $attributes = [], array $shares = []): Dashboard {
        return ModelStub::make(Dashboard::class, [
            'id' => ModelStub::ulid('dashboard'),
            'tenant_id' => $this->tenant->getKey(),
            'owner_id' => $this->owner->getKey(),
            'is_tenant_wide' => false,
            'name' => 'Guarded board',
            ...$attributes,
        ], ['shares' => new EloquentCollection($shares)]);
    };

    /** @var callable(string, string, bool):DashboardShare */
    $this->grant = fn (string $granteeType, string $granteeId, bool $canEdit): DashboardShare => ModelStub::make(
        DashboardShare::class,
        [
            'tenant_id' => $this->tenant->getKey(),
            'grantee_type' => $granteeType,
            'grantee_id' => $granteeId,
            'can_edit' => $canEdit,
        ],
    );

    /** @var callable(string):Role */
    $this->escalatedRole = fn (string $seed): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'authority' => RoleAuthority::ScopeAdmin->value,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses every ability on a dashboard that belongs to another tenant', function (): void {
    $foreign = ($this->dashboard)(['tenant_id' => ModelStub::ulid('other-tenant')]);

    expect($this->policy->view($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->update($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->delete($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->share($this->owner, $foreign))->toBeFalse();
});

it('refuses every ability to a caller without a tenant of their own', function (): void {
    $homeless = ($this->member)('homeless');
    $homeless->tenant_id = null;

    expect($this->policy->view($homeless, ($this->dashboard)()))->toBeFalse();
});

it('grants the owner the full set of abilities', function (): void {
    $dashboard = ($this->dashboard)();

    expect($this->policy->view($this->owner, $dashboard))->toBeTrue()
        ->and($this->policy->update($this->owner, $dashboard))->toBeTrue()
        ->and($this->policy->delete($this->owner, $dashboard))->toBeTrue()
        ->and($this->policy->share($this->owner, $dashboard))->toBeTrue();
});

it('hides a private dashboard from a tenant member who holds no grant', function (): void {
    $stranger = ($this->member)('stranger');

    expect($this->policy->view($stranger, ($this->dashboard)()))->toBeFalse();
});

it('lets every tenant member read a tenant wide dashboard without handing them write rights', function (): void {
    $stranger = ($this->member)('stranger');
    $dashboard = ($this->dashboard)(['is_tenant_wide' => true]);

    expect($this->policy->view($stranger, $dashboard))->toBeTrue()
        ->and($this->policy->update($stranger, $dashboard))->toBeFalse()
        ->and($this->policy->delete($stranger, $dashboard))->toBeFalse()
        ->and($this->policy->share($stranger, $dashboard))->toBeFalse();
});

it('lets a directly granted user read the dashboard and write it only with the edit flag', function (): void {
    $reader = ($this->member)('reader');
    $editor = ($this->member)('editor');

    $readOnly = ($this->dashboard)([], [($this->grant)($reader->getMorphClass(), (string) $reader->getKey(), false)]);
    $writable = ($this->dashboard)([], [($this->grant)($editor->getMorphClass(), (string) $editor->getKey(), true)]);

    expect($this->policy->view($reader, $readOnly))->toBeTrue()
        ->and($this->policy->update($reader, $readOnly))->toBeFalse()
        ->and($this->policy->view($editor, $writable))->toBeTrue()
        ->and($this->policy->update($editor, $writable))->toBeTrue();
});

it('never lets a granted editor delete or re-share the dashboard', function (): void {
    $editor = ($this->member)('editor');
    $dashboard = ($this->dashboard)([], [($this->grant)($editor->getMorphClass(), (string) $editor->getKey(), true)]);

    expect($this->policy->delete($editor, $dashboard))->toBeFalse()
        ->and($this->policy->share($editor, $dashboard))->toBeFalse();
});

it('reaches a grantee through their team membership and stops at a team they do not belong to', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('granted-team'), 'tenant_id' => $this->tenant->getKey()]);
    $other = ModelStub::make(Team::class, ['id' => ModelStub::ulid('other-team'), 'tenant_id' => $this->tenant->getKey()]);

    $insider = ($this->member)('insider', [], [$team]);
    $outsider = ($this->member)('outsider', [], [$other]);

    $dashboard = ($this->dashboard)([], [($this->grant)($team->getMorphClass(), (string) $team->getKey(), true)]);

    expect($this->policy->view($insider, $dashboard))->toBeTrue()
        ->and($this->policy->update($insider, $dashboard))->toBeTrue()
        ->and($this->policy->view($outsider, $dashboard))->toBeFalse();
});

it('reaches a grantee through a role they hold and stops at a role they do not hold', function (): void {
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('granted-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'granted-role',
    ]);

    $holder = ($this->member)('role-holder', [$role]);
    $nonHolder = ($this->member)('no-role');

    $dashboard = ($this->dashboard)([], [($this->grant)($role->getMorphClass(), (string) $role->getKey(), false)]);

    expect($this->policy->view($holder, $dashboard))->toBeTrue()
        ->and($this->policy->view($nonHolder, $dashboard))->toBeFalse();
});

it('ignores a grant whose grantee type belongs to no known holder', function (): void {
    $stranger = ($this->member)('stranger');
    $dashboard = ($this->dashboard)([], [($this->grant)(Dashboard::class, (string) $stranger->getKey(), true)]);

    expect($this->policy->view($stranger, $dashboard))->toBeFalse();
});

it('hands an escalated authority every ability on a foreign dashboard of their own tenant', function (): void {
    $admin = ($this->member)('admin', [($this->escalatedRole)('scope-admin')]);
    $dashboard = ($this->dashboard)();

    expect($this->policy->view($admin, $dashboard))->toBeTrue()
        ->and($this->policy->update($admin, $dashboard))->toBeTrue()
        ->and($this->policy->delete($admin, $dashboard))->toBeTrue()
        ->and($this->policy->share($admin, $dashboard))->toBeTrue();
});

it('opens listing and creation to every caller because the row check happens per dashboard', function (): void {
    $stranger = ($this->member)('stranger');

    expect($this->policy->viewAny($stranger))->toBeTrue()
        ->and($this->policy->create($stranger))->toBeTrue();
});

it('delegates every widget ability to the dashboard that carries the widget', function (): void {
    $viewer = ($this->member)('viewer');
    $dashboard = ($this->dashboard)();

    $widget = ModelStub::make(
        DashboardWidget::class,
        ['dashboard_id' => $dashboard->getKey()],
        ['dashboard' => $dashboard],
    );

    $spy = GateSpy::allowing('update');
    $policy = new DashboardWidgetPolicy;

    expect($policy->view($viewer, $widget))->toBeFalse()
        ->and($policy->update($viewer, $widget))->toBeTrue()
        ->and($policy->delete($viewer, $widget))->toBeTrue()
        ->and($spy->abilities())->toBe(['view', 'update', 'update'])
        ->and($spy->calls[0]['arguments'][0])->toBe($dashboard);
});

it('grants a user their own dashboard only while it carries their key as owner', function (): void {
    $dashboard = ($this->dashboard)(['owner_id' => ModelStub::ulid('somebody-else')]);

    expect($this->policy->view($this->owner, $dashboard))->toBeFalse();
});
