<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

it('assigns a role without scope', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'nubos:super-admin', 'scope' => 'platform']);

    $user->assignRole($role);

    expect($user->hasRole('nubos:super-admin'))->toBeTrue();
});

it('assigns a role with scope', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $role = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);

    $user->assignRole($role, $scopeModel);

    expect($user->hasRole('admin', $scopeModel))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeFalse();
});

it('removes a role', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'member', 'scope' => 'organization']);

    $user->assignRole($role);
    $user->removeRole($role);

    expect($user->hasRole('member'))->toBeFalse();
});

it('removes a scoped role without affecting other scopes', function (): void {
    $user = User::factory()->create();
    $scopeA = User::factory()->create();
    $scopeB = User::factory()->create();
    $role = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);

    $user->assignRole($role, $scopeA);
    $user->assignRole($role, $scopeB);

    $user->removeRole($role, $scopeA);

    expect($user->hasRole('admin', $scopeA))->toBeFalse()
        ->and($user->hasRole('admin', $scopeB))->toBeTrue();
});

it('checks permission through role', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);
    $permission = Permission::factory()->create(['name' => 'members.view', 'scope' => 'organization']);

    $role->permissions()->attach($permission);
    $user->assignRole($role);

    expect($user->hasPermission('members.view'))->toBeTrue()
        ->and($user->hasPermission('members.delete'))->toBeFalse();
});

it('checks scoped permission through scoped role', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $role = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);
    $permission = Permission::factory()->create(['name' => 'members.invite', 'scope' => 'organization']);

    $role->permissions()->attach($permission);
    $user->assignRole($role, $scopeModel);

    expect($user->hasPermission('members.invite', $scopeModel))->toBeTrue()
        ->and($user->hasPermission('members.invite'))->toBeFalse();
});

it('returns roles for a specific scope', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $platformRole = Role::factory()->create(['name' => 'nubos:support', 'scope' => 'platform']);
    $orgRole = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);

    $user->assignRole($platformRole);
    $user->assignRole($orgRole, $scopeModel);

    expect($user->rolesFor())->toHaveCount(1)
        ->and($user->rolesFor()->first()->name)->toBe('nubos:support')
        ->and($user->rolesFor($scopeModel))->toHaveCount(1)
        ->and($user->rolesFor($scopeModel)->first()->name)->toBe('admin');
});

it('returns unique permissions for a scope', function (): void {
    $user = User::factory()->create();
    $roleA = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);
    $roleB = Role::factory()->create(['name' => 'owner', 'scope' => 'organization']);
    $permission = Permission::factory()->create(['name' => 'members.view', 'scope' => 'organization']);

    $roleA->permissions()->attach($permission);
    $roleB->permissions()->attach($permission);
    $user->assignRole($roleA);
    $user->assignRole($roleB);

    expect($user->permissionsFor())->toHaveCount(1);
});
