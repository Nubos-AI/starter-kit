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

it('does not widen a granted permission to unrelated abilities', function (): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [($this->roleWith)('viewer', [], ['members.view'])],
    );

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeTrue()
        ->and(($this->resolver)()->allows($user, 'members.update'))->toBeFalse();
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

it('withdraws every permission, even an escalated authority, from a user who is not active', function (UserStatus $status): void {
    $user = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey(), 'status' => $status->value],
        [
            ($this->roleWith)('super', ['authority' => RoleAuthority::SuperAdmin->value]),
            ($this->roleWith)('viewer', [], ['members.view']),
        ],
    );

    expect(($this->resolver)()->allows($user, 'members.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('members.view'))->toBeFalse();
})->with([
    'blocked' => [UserStatus::Blocked],
    'invited' => [UserStatus::Invited],
    'deleted' => [UserStatus::Deleted],
]);
