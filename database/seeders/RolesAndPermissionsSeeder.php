<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlatformRoles();
        $this->seedPlatformPermissions();
        $this->assignPlatformPermissions();
    }

    private function seedPlatformRoles(): void
    {
        $roles = [
            ['name' => 'nubos:super-admin', 'scope' => 'platform', 'is_system' => true],
            ['name' => 'nubos:support', 'scope' => 'platform', 'is_system' => true],
            ['name' => 'nubos:billing', 'scope' => 'platform', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role['name'], 'scope' => $role['scope']],
                $role,
            );
        }
    }

    private function seedPlatformPermissions(): void
    {
        $permissions = [
            ['name' => 'tenants.view', 'group' => 'tenant-management', 'scope' => 'platform'],
            ['name' => 'tenants.create', 'group' => 'tenant-management', 'scope' => 'platform'],
            ['name' => 'tenants.update', 'group' => 'tenant-management', 'scope' => 'platform'],
            ['name' => 'tenants.delete', 'group' => 'tenant-management', 'scope' => 'platform'],
            ['name' => 'users.view', 'group' => 'user-management', 'scope' => 'platform'],
            ['name' => 'users.impersonate', 'group' => 'user-management', 'scope' => 'platform'],
            ['name' => 'billing.view', 'group' => 'billing', 'scope' => 'platform'],
            ['name' => 'billing.update', 'group' => 'billing', 'scope' => 'platform'],
            ['name' => 'subscriptions.manage', 'group' => 'billing', 'scope' => 'platform'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission['name'], 'scope' => $permission['scope']],
                [...$permission, 'is_system' => true],
            );
        }
    }

    private function assignPlatformPermissions(): void
    {
        $support = Role::query()->where('name', 'nubos:support')->where('scope', 'platform')->first();
        $billing = Role::query()->where('name', 'nubos:billing')->where('scope', 'platform')->first();

        $support?->permissions()->syncWithoutDetaching(
            Permission::query()
                ->where('scope', 'platform')
                ->whereIn('group', ['tenant-management', 'user-management'])
                ->whereIn('name', ['tenants.view', 'users.view', 'users.impersonate'])
                ->pluck('id'),
        );

        $billing?->permissions()->syncWithoutDetaching(
            Permission::query()
                ->where('scope', 'platform')
                ->where('group', 'billing')
                ->pluck('id'),
        );
    }
}
