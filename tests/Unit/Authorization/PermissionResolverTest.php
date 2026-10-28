<?php

declare(strict_types=1);

use App\Enums\Authorization\PermissionEffect;
use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\TeamRoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(string, array<string, mixed>, list<string>):Role */
    $this->roleWith = function (string $seed, array $attributes = [], array $permissionNames = []): Role {
        $permissions = array_map(fn (string $name): Permission => ModelStub::make(Permission::class, [
            'id' => ModelStub::ulid($name),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $name,
        ]), $permissionNames);

        return ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            ...$attributes,
        ], ['permissions' => ModelStub::collection(Permission::class, [])->merge($permissions)]);
    };

    $this->resolver = fn (): PermissionResolver => app(PermissionResolver::class);
});

afterEach(function (): void {
    Auth::forgetUser();
    AccessContext::forgetTeam();
    AccessContext::forgetTenant();
});

it('denies every ability to a user without any role', function (): void {
    $user = RoleHolder::make(['tenant_id' => $this->tenant->getKey()]);

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse()
        ->and(($this->resolver)()->allows($user, 'roles.view'))->toBeFalse();
});

it('does not widen a granted permission to unrelated abilities', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('viewer', [], ['members.view'])],
    );

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeTrue()
        ->and(($this->resolver)()->allows($user, 'members.update'))->toBeFalse();
});

it('grants every ability to an unrestricted authority', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value])],
    );

    expect(($this->resolver)()->allows($user, 'anything.nobody.defined'))->toBeTrue();
});

it('grants every ability to an administration within its own scope', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('scope', ['authority' => RoleAuthority::ScopeAdmin->value])],
    );

    expect(($this->resolver)()->allows($user, 'anything.nobody.defined'))->toBeTrue();
});

it('maps a list of abilities in one pass', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('viewer', [], ['members.view'])],
    );

    expect(($this->resolver)()->map($user, ['members.view', 'members.update']))
        ->toBe(['members.view' => true, 'members.update' => false]);
});

it('lets a role on the active team grant a permission to its member', function (): void {
    $team = TeamRoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('team-viewer', [], ['members.view'])],
    );

    app()->instance('current_team', $team);

    $user = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeTrue()
        ->and(($this->resolver)()->verdictFor($user, 'members.view'))->toBeNull();
});

it('lets a deny on the user beat a grant from the team', function (): void {
    $team = TeamRoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('team-viewer', [], ['members.view'])],
    );

    app()->instance('current_team', $team);

    $user = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()])
        ->withOverrides(['members.view' => PermissionEffect::Deny]));

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse();
});

it('lets a deny on the team beat a grant on the same team', function (): void {
    $team = TeamRoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('team-viewer', [], ['members.view'])],
    )->withOverrides(['members.view' => PermissionEffect::Deny]);

    app()->instance('current_team', $team);

    $user = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse();
});

it('keeps an escalated authority despite a deny override', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value])],
    )->withOverrides(['members.view' => PermissionEffect::Deny]);

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeTrue();
});

it('never consults a team for a service account', function (): void {
    $team = TeamRoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('team-viewer', [], ['members.view'])],
    );

    app()->instance('current_team', $team);

    $service = AccessContext::actAs(RoleHolder::make([
        'tenant_id' => $this->tenant->getKey(),
        'is_service' => true,
    ]));

    expect(($this->resolver)()->allows($service, 'members.view'))->toBeFalse();
});

it('grants a service account only what its token abilities map onto', function (): void {
    $service = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'is_service' => true],
        [($this->roleWith)('integration', [], ['members.view'])],
    );

    expect(($this->resolver)()->allows($service, 'members.view'))->toBeFalse();
});
