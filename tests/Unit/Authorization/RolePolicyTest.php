<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Policies\Authorization\PermissionPolicy;
use App\Policies\Authorization\RolePolicy;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(string, array<string, mixed>):Role */
    $this->roleWith = fn (string $seed, array $attributes = []): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'is_system' => false,
        ...$attributes,
    ]);

    /** @var callable(list<Role>):RoleHolder */
    $this->actor = fn (array $roles = []): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
    );

    $this->scopeAdminRole = ($this->roleWith)('scope-admin-role', ['authority' => RoleAuthority::ScopeAdmin->value]);
    $this->superAdminRole = ($this->roleWith)('super-admin-role', ['authority' => RoleAuthority::SuperAdmin->value]);

    $this->policy = new RolePolicy;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('opens the role list to the view permission alone', function (): void {
    $resolver = AccessContext::grant('roles.view');

    expect($this->policy->viewAny(($this->actor)()))->toBeTrue()
        ->and($resolver->askedFor)->toBe(['roles.view']);
});

it('closes the role list without the view permission', function (): void {
    AccessContext::grant('roles.update');

    expect($this->policy->viewAny(($this->actor)()))->toBeFalse();
});

it('lets a plain role manager change a custom role', function (): void {
    AccessContext::grant('roles.update');

    expect($this->policy->update(($this->actor)(), ($this->roleWith)('sales')))->toBeTrue();
});

it('keeps a system role away from a plain role manager', function (): void {
    AccessContext::grant('roles.update', 'roles.delete');

    $system = ($this->roleWith)('system', ['is_system' => true]);

    expect($this->policy->update(($this->actor)(), $system))->toBeFalse()
        ->and($this->policy->delete(($this->actor)(), $system))->toBeFalse();
});

it('opens a system role to an escalated actor without asking for the ability', function (): void {
    $resolver = AccessContext::grant();

    $system = ($this->roleWith)('system', ['is_system' => true]);
    $actor = ($this->actor)([$this->scopeAdminRole]);

    expect($this->policy->update($actor, $system))->toBeTrue()
        ->and($this->policy->delete($actor, $system))->toBeTrue()
        ->and($resolver->askedFor)->toBeEmpty();
});

it('reserves a super admin role for a super admin actor', function (): void {
    AccessContext::grant('roles.update', 'roles.delete');

    $scopeAdmin = ($this->actor)([$this->scopeAdminRole]);

    expect($this->policy->update($scopeAdmin, $this->superAdminRole))->toBeFalse()
        ->and($this->policy->delete($scopeAdmin, $this->superAdminRole))->toBeFalse()
        ->and($this->policy->assign($scopeAdmin, $this->superAdminRole))->toBeFalse();
});

it('lets a super admin steer a super admin role', function (): void {
    AccessContext::grant('roles.update');

    $superAdmin = ($this->actor)([$this->superAdminRole]);

    expect($this->policy->update($superAdmin, $this->superAdminRole))->toBeTrue()
        ->and($this->policy->assign($superAdmin, $this->superAdminRole))->toBeTrue();
});

it('demands the update permission before assigning a role', function (): void {
    $resolver = AccessContext::grant('roles.view');

    expect($this->policy->assign(($this->actor)(), ($this->roleWith)('sales')))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['roles.update']);
});

it('assigns a plain role on the update permission alone', function (): void {
    AccessContext::grant('roles.update');

    expect($this->policy->assign(($this->actor)(), ($this->roleWith)('sales')))->toBeTrue();
});

it('lets a scope admin assign a scope admin role', function (): void {
    AccessContext::grant('roles.update');

    expect($this->policy->assign(($this->actor)([$this->scopeAdminRole]), $this->scopeAdminRole))->toBeTrue();
});

it('keeps permissions read only for everyone', function (): void {
    AccessContext::grant('roles.update', 'roles.create', 'roles.delete');

    $policy = new PermissionPolicy;
    $actor = ($this->actor)([$this->superAdminRole]);
    $permission = ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid('permission'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'members.view',
    ]);

    expect($policy->create($actor))->toBeFalse()
        ->and($policy->update($actor, $permission))->toBeFalse()
        ->and($policy->delete($actor, $permission))->toBeFalse();
});
