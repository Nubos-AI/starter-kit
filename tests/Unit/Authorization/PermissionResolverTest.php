<?php

declare(strict_types=1);

use App\Enums\Authorization\PermissionEffect;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;
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

it('grants every ability to an escalated authority', function (string $seed, RoleAuthority $authority): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)($seed, ['authority' => $authority->value])],
    );

    expect(($this->resolver)()->allows($user, 'anything.nobody.defined'))->toBeTrue();
})->with([
    'unrestricted authority' => ['super', RoleAuthority::SuperAdmin],
    'administration within its own scope' => ['scope', RoleAuthority::ScopeAdmin],
]);

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

it('never grants a service account the permissions of a role assigned to it', function (): void {
    $service = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'is_service' => true],
        [($this->roleWith)('integration', [], ['members.view'])],
    );

    expect(($this->resolver)()->allows($service, 'members.view'))->toBeFalse();
});

it('grants a service account nothing, not even what the abilities of a token it presents map onto', function (): void {
    $token = ModelStub::make(PersonalAccessToken::class, ['id' => 7]);
    $token->abilities = ['members:read', 'members:write'];

    $service = RoleHolder::make(['tenant_id' => $this->tenant->getKey(), 'is_service' => true]);
    $service->withAccessToken($token);

    expect(($this->resolver)()->allows($service, 'members.view'))->toBeFalse()
        ->and(($this->resolver)()->allows($service, 'members.create'))->toBeFalse()
        ->and(($this->resolver)()->verdictFor($service, 'members.view'))->toBeNull();
});

it('withdraws every permission from a user who is not active', function (UserStatus $status): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'status' => $status->value],
        [($this->roleWith)('viewer', [], ['members.view'])],
    )->withOverrides(['roles.view' => PermissionEffect::Allow]);

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse()
        ->and(($this->resolver)()->allows($user, 'roles.view'))->toBeFalse()
        ->and(($this->resolver)()->verdictFor($user, 'members.view'))->toBeFalse()
        ->and(($this->resolver)()->map($user, ['members.view', 'roles.view']))->toBe(['members.view' => false, 'roles.view' => false]);
})->with([
    'blocked' => [UserStatus::Blocked],
    'invited' => [UserStatus::Invited],
    'deleted' => [UserStatus::Deleted],
]);

it('withdraws even an escalated authority from a user who is not active', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'status' => UserStatus::Blocked->value],
        [($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value])],
    );

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('members.view'))->toBeFalse();
});

it('keeps the permissions of an accepted user', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'status' => UserStatus::Accepted->value],
        [($this->roleWith)('viewer', [], ['members.view'])],
    );

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeTrue()
        ->and(($this->resolver)()->verdictFor($user, 'members.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('members.view'))->toBeTrue();
});
