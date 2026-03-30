<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;

it('creates a permission with required attributes', function (): void {
    $permission = Permission::factory()->create([
        'name' => 'members.view',
        'group' => 'members',
        'scope' => 'organization',
        'is_system' => true,
    ]);

    expect($permission)
        ->name->toBe('members.view')
        ->group->toBe('members')
        ->scope->toBe('organization')
        ->is_system->toBeTrue();
});

it('enforces unique name per scope', function (): void {
    Permission::factory()->create(['name' => 'members.view', 'scope' => 'organization']);

    expect(fn () => Permission::factory()->create(['name' => 'members.view', 'scope' => 'organization']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows same name in different scopes', function (): void {
    $orgPerm = Permission::factory()->create(['name' => 'members.view', 'scope' => 'organization']);
    $teamPerm = Permission::factory()->create(['name' => 'members.view', 'scope' => 'team']);

    expect($orgPerm->id)->not->toBe($teamPerm->id);
});

it('has many roles via pivot', function (): void {
    $permission = Permission::factory()->create();
    $roles = Role::factory()->count(2)->create();

    $permission->roles()->attach($roles->pluck('id'));

    expect($permission->roles)->toHaveCount(2);
});

it('allows nullable group', function (): void {
    $permission = Permission::factory()->create(['group' => null]);

    expect($permission->group)->toBeNull();
});

it('soft deletes', function (): void {
    $permission = Permission::factory()->create();

    $permission->delete();

    expect($permission->trashed())->toBeTrue()
        ->and(Permission::withTrashed()->find($permission->id))->not->toBeNull();
});
