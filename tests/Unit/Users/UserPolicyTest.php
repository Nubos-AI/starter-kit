<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Policies\Users\UserPolicy;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Teams\TeamMembershipDirectory;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    $this->memberships = new FakeTeamMembershipDirectory;
    app()->instance(TeamMembershipDirectory::class, $this->memberships);

    /** @var callable(string):Permission */
    $this->permission = fn (string $name): Permission => ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid($name),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $name,
    ]);

    /** @var callable(string, ?RoleAuthority, list<string>):Role */
    $this->role = function (string $seed, ?RoleAuthority $authority = null, array $permissionNames = []): Role {
        $role = ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            'authority' => $authority,
        ], ['permissions' => ModelStub::collection(Permission::class, [])->merge(
            array_map(fn (string $name): Permission => ($this->permission)($name), $permissionNames),
        )]);

        $this->directory->withRoles([$role]);

        return $role;
    };

    /** @var callable(string, list<Role>):RoleHolder */
    $this->holder = function (string $seed, array $roles): RoleHolder {
        $holder = RoleHolder::make(
            ['tenant_id' => (string) $this->tenant->getKey(), 'is_service' => false],
            $roles,
            $seed,
        );

        $this->directory->withAssignedRoles($holder, array_map(
            static fn (Role $role): string => (string) $role->getKey(),
            $roles,
        ));

        return $holder;
    };

    FakeManagedUserResolver::reachingTheTenant()->managing([
        ModelStub::ulid('super-admin'),
        ModelStub::ulid('second-super-admin'),
        ModelStub::ulid('powerful-admin'),
        ModelStub::ulid('plain-member'),
    ]);

    $this->superAdmin = ($this->holder)('super-admin', [($this->role)('super', RoleAuthority::SuperAdmin)]);
    $this->secondSuperAdmin = ($this->holder)('second-super-admin', [($this->role)('super', RoleAuthority::SuperAdmin)]);
    $this->plainManager = ($this->holder)('plain-manager', [($this->role)('manager')]);
    $this->escalatedManager = ($this->holder)('escalated-manager', [($this->role)('owner', RoleAuthority::ScopeAdmin)]);
    $this->helpdesk = ($this->holder)('helpdesk', [($this->role)('helpdesk', null, ['members.password', 'members.block', 'members.remove', 'members.update', 'records.view'])]);
    $this->powerfulAdmin = ($this->holder)('powerful-admin', [($this->role)('admin', null, ['members.password', 'roles.update', 'records.view'])]);
    $this->plainMember = ($this->holder)('plain-member', [($this->role)('member', null, ['records.view'])]);

    $this->policy = fn (): UserPolicy => app(UserPolicy::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a manager without authority to block a super admin', function (): void {
    expect(($this->policy)()->block($this->plainManager, $this->superAdmin))->toBeFalse();
});

it('refuses a manager without authority to delete a super admin', function (): void {
    expect(($this->policy)()->delete($this->plainManager, $this->superAdmin))->toBeFalse();
});

it('refuses a scope admin to steer a super admin account', function (): void {
    $policy = ($this->policy)();

    expect($policy->block($this->escalatedManager, $this->superAdmin))->toBeFalse()
        ->and($policy->delete($this->escalatedManager, $this->superAdmin))->toBeFalse()
        ->and($policy->updatePassword($this->escalatedManager, $this->superAdmin))->toBeFalse()
        ->and($policy->update($this->escalatedManager, $this->superAdmin))->toBeFalse();
});

it('lets a super admin steer another super admin account', function (): void {
    $policy = ($this->policy)();

    expect($policy->block($this->secondSuperAdmin, $this->superAdmin))->toBeTrue()
        ->and($policy->delete($this->secondSuperAdmin, $this->superAdmin))->toBeTrue()
        ->and($policy->updatePassword($this->secondSuperAdmin, $this->superAdmin))->toBeTrue();
});

it('refuses a helpdesk to steer an account holding permissions beyond its own', function (): void {
    $policy = ($this->policy)();

    expect($policy->updatePassword($this->helpdesk, $this->powerfulAdmin))->toBeFalse()
        ->and($policy->block($this->helpdesk, $this->powerfulAdmin))->toBeFalse()
        ->and($policy->delete($this->helpdesk, $this->powerfulAdmin))->toBeFalse()
        ->and($policy->update($this->helpdesk, $this->powerfulAdmin))->toBeFalse();
});

it('lets a helpdesk steer an account whose permissions it holds itself', function (): void {
    $policy = ($this->policy)();

    expect($policy->updatePassword($this->helpdesk, $this->plainMember))->toBeTrue()
        ->and($policy->block($this->helpdesk, $this->plainMember))->toBeTrue()
        ->and($policy->delete($this->helpdesk, $this->plainMember))->toBeTrue()
        ->and($policy->update($this->helpdesk, $this->plainMember))->toBeTrue();
});

it('refuses a helpdesk when a team of the target carries permissions beyond its own', function (): void {
    $team = AccessContext::team($this->tenant, 'admins');
    AccessContext::forgetTeam();

    $this->memberships->withTeams($this->plainMember, [(string) $team->getKey()]);
    $this->directory->withTeamRoles($team, [(string) ($this->role)('team-admin', null, ['roles.update'])->getKey()]);

    expect(($this->policy)()->updatePassword($this->helpdesk, $this->plainMember))->toBeFalse();
});

it('refuses a helpdesk when an allow override grants the target a permission beyond its own', function (): void {
    $this->directory->withPermissions([($this->permission)('roles.update')]);
    $this->directory->withAllowedPermissions($this->plainMember, [ModelStub::ulid('roles.update')]);

    expect(($this->policy)()->updatePassword($this->helpdesk, $this->plainMember))->toBeFalse();
});

it('refuses a helpdesk when a team of the target carries an allow override beyond its own', function (): void {
    $team = AccessContext::team($this->tenant, 'admins');
    AccessContext::forgetTeam();

    $this->memberships->withTeams($this->plainMember, [(string) $team->getKey()]);
    $this->directory->withPermissions([($this->permission)('roles.update')]);
    $this->directory->withAllowedPermissions($team, [ModelStub::ulid('roles.update')]);

    expect(($this->policy)()->updatePassword($this->helpdesk, $this->plainMember))->toBeFalse();
});

it('judges a whole list of accounts with one lookup per kind instead of one per account', function (): void {
    $team = AccessContext::team($this->tenant, 'support');
    AccessContext::forgetTeam();

    $this->memberships->withTeams($this->plainMember, [(string) $team->getKey()]);
    $this->directory->withTeamRoles($team, [(string) ($this->role)('team-member', null, ['records.view'])->getKey()]);
    $this->directory->askedFor = [];

    $keys = [(string) $this->plainMember->getKey(), (string) $this->powerfulAdmin->getKey(), (string) $this->superAdmin->getKey()];

    $steerable = app(PermissionSubsetGuard::class)->steerableAccountKeys(
        $this->helpdesk,
        $keys,
        [
            $keys[0] => [ModelStub::ulid('member')],
            $keys[1] => [ModelStub::ulid('admin')],
            $keys[2] => [ModelStub::ulid('super')],
        ],
        [$keys[0] => [(string) $team->getKey()]],
    );

    expect($steerable)->toBe([$keys[0]])
        ->and(array_count_values($this->directory->askedFor))->toEqual([
            'roleIdsByTeam' => 1,
            'allowedPermissionIdsByHolder' => 2,
            'rolesByIds' => 1,
            'permissionsByIds' => 1,
            'restrictedFieldIds' => 1,
            'fieldGrantsOfRole' => 2,
        ]);
});

it('refuses to resend an invitation to a user outside the reach of the actor', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')])->managing([]);

    expect(($this->policy)()->resendInvitation($this->helpdesk, $this->plainMember))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['members.invite']);
});

it('lets a manager resend an invitation to a user within their invite reach', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')])->managing([ModelStub::ulid('plain-member')]);

    expect(($this->policy)()->resendInvitation($this->helpdesk, $this->plainMember))->toBeTrue();
});

it('refuses to re-invite an account whose permissions exceed those of the actor so its roles cannot be withdrawn', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->managing([ModelStub::ulid('powerful-admin'), ModelStub::ulid('plain-member')]);
    $policy = ($this->policy)();

    expect($policy->resendInvitation($this->helpdesk, $this->powerfulAdmin))->toBeFalse()
        ->and($policy->resendInvitation($this->helpdesk, $this->superAdmin))->toBeFalse()
        ->and($policy->resendInvitation($this->helpdesk, $this->plainMember))->toBeTrue();
});
