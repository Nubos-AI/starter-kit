<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class OrgRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedPermissions();
        $this->assignPermissions();
    }

    private function seedRoles(): void
    {
        $roles = [
            ['name' => 'owner', 'scope' => 'tenant', 'is_system' => true],
            ['name' => 'admin', 'scope' => 'tenant', 'is_system' => true],
            ['name' => 'member', 'scope' => 'tenant', 'is_system' => true],
        ['name' => 'team:lead', 'scope' => 'team', 'is_system' => true],
        ['name' => 'team:member', 'scope' => 'team', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role['name'], 'scope' => $role['scope']],
                $role,
            );
        }
    }

    private function seedPermissions(): void
    {
        $permissions = [
            ['name' => 'org.settings.view', 'group' => 'settings', 'scope' => 'tenant'],
            ['name' => 'org.settings.update', 'group' => 'settings', 'scope' => 'tenant'],
            ['name' => 'org.delete', 'group' => 'settings', 'scope' => 'tenant'],
            ['name' => 'members.view', 'group' => 'members', 'scope' => 'tenant'],
            ['name' => 'members.invite', 'group' => 'members', 'scope' => 'tenant'],
            ['name' => 'members.remove', 'group' => 'members', 'scope' => 'tenant'],
            ['name' => 'members.change-role', 'group' => 'members', 'scope' => 'tenant'],
            ['name' => 'teams.view', 'group' => 'teams', 'scope' => 'tenant'],
            ['name' => 'teams.create', 'group' => 'teams', 'scope' => 'tenant'],
            ['name' => 'teams.update', 'group' => 'teams', 'scope' => 'tenant'],
            ['name' => 'teams.delete', 'group' => 'teams', 'scope' => 'tenant'],
            ['name' => 'teams.members.manage', 'group' => 'teams', 'scope' => 'tenant'],
        ['name' => 'team.settings.view', 'group' => 'team-settings', 'scope' => 'team'],
        ['name' => 'team.settings.update', 'group' => 'team-settings', 'scope' => 'team'],
        ['name' => 'team.members.view', 'group' => 'team-members', 'scope' => 'team'],
        ['name' => 'team.members.add', 'group' => 'team-members', 'scope' => 'team'],
        ['name' => 'team.members.remove', 'group' => 'team-members', 'scope' => 'team'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission['name'], 'scope' => $permission['scope']],
                [...$permission, 'is_system' => true],
            );
        }
    }

    private function assignPermissions(): void
    {
        $mapping = [
        'admin' => [
            'org.settings.view',
            'members.view',
            'members.invite',
            'members.remove',
            'members.change-role',
            'teams.view',
            'teams.create',
            'teams.update',
            'teams.delete',
            'teams.members.manage',
        ],
        'member' => [
            'members.view',
            'teams.view',
        ],
        'team:lead' => [
            'team.settings.view',
            'team.settings.update',
            'team.members.view',
            'team.members.add',
            'team.members.remove',
        ],
        'team:member' => [
            'team.members.view',
        ],
        ];

        foreach ($mapping as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->first();

            if ($role === null) {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id');

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
