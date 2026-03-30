<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;

it('creates a role with required attributes', function (): void {
    $role = Role::factory()->create([
        'name' => 'admin',
        'scope' => 'organization',
        'is_system' => true,
    ]);

    expect($role)
        ->name->toBe('admin')
        ->scope->toBe('organization')
        ->is_system->toBeTrue();
});

it('enforces unique name per scope', function (): void {
    Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);

    expect(fn () => Role::factory()->create(['name' => 'admin', 'scope' => 'organization']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows same name in different scopes', function (): void {
    $orgRole = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);
    $teamRole = Role::factory()->create(['name' => 'admin', 'scope' => 'team']);

    expect($orgRole->id)->not->toBe($teamRole->id);
});

it('has many permissions via pivot', function (): void {
    $role = Role::factory()->create();
    $permissions = Permission::factory()->count(3)->create();

    $role->permissions()->attach($permissions->pluck('id'));

    expect($role->permissions)->toHaveCount(3);
});

it('soft deletes', function (): void {
    $role = Role::factory()->create();

    $role->delete();

    expect($role->trashed())->toBeTrue()
        ->and(Role::withTrashed()->find($role->id))->not->toBeNull();
});
