<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Policies\Users\UserPolicy;
use App\Support\Authorization\AuthorizationDirectory;
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
        ModelStub::ulid('powerful-admin'),
        ModelStub::ulid('plain-member'),
    ]);

    $this->helpdesk = ($this->holder)('helpdesk', [($this->role)('helpdesk', null, ['members.password', 'members.block', 'members.remove', 'members.update', 'records.view'])]);
    $this->powerfulAdmin = ($this->holder)('powerful-admin', [($this->role)('admin', null, ['members.password', 'roles.update', 'records.view'])]);
    $this->plainMember = ($this->holder)('plain-member', [($this->role)('member', null, ['records.view'])]);

    $this->policy = fn (): UserPolicy => app(UserPolicy::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
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
