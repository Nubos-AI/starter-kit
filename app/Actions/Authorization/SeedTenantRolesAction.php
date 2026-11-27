<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Support\Str;

class SeedTenantRolesAction
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function execute(): Role
    {
        $owner = $this->persist($this->ownerDefinition());

        foreach ($this->delegatedDefinitions() as $definition) {
            $this->persist($definition);
        }

        return $owner;
    }

    /**
     * @return list<array{name: string, authority: RoleAuthority|null, system: bool, permissions: list<string>}>
     */
    public function definitions(): array
    {
        return [$this->ownerDefinition(), ...$this->delegatedDefinitions()];
    }

    /**
     * @return array{name: string, authority: RoleAuthority|null, system: bool, permissions: list<string>}
     */
    private function ownerDefinition(): array
    {
        return [
            'name' => (string) config('permissions.tenant_roles.owner.name'),
            'authority' => RoleAuthority::ScopeAdmin,
            'system' => true,
            'permissions' => [],
        ];
    }

    /**
     * @return list<array{name: string, authority: RoleAuthority|null, system: bool, permissions: list<string>}>
     */
    private function delegatedDefinitions(): array
    {
        return [
            [
                'name' => (string) config('permissions.tenant_roles.admin.name'),
                'authority' => null,
                'system' => true,
                'permissions' => $this->administrablePermissionNames(),
            ],
            [
                'name' => (string) config('permissions.tenant_roles.member.name'),
                'authority' => null,
                'system' => true,
                'permissions' => $this->configuredNames('permissions.tenant_roles.member.permissions'),
            ],
        ];
    }

    /**
     * @param  array{name: string, authority: RoleAuthority|null, system: bool, permissions: list<string>}  $definition
     */
    private function persist(array $definition): Role
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $definition['name'], 'scope' => RoleScope::Tenant],
            [
                'authority' => $definition['authority'],
                'is_system' => $definition['system'],
                'grants_subteam_visibility' => false,
            ],
        );

        $role->permissions()->syncWithoutDetaching($this->permissionIds($definition['permissions']));

        return $role;
    }

    /**
     * @return list<string>
     */
    private function administrablePermissionNames(): array
    {
        $excludedGroups = $this->configuredNames('permissions.tenant_roles.admin.excluded_groups');

        $names = array_filter(
            $this->catalog->globalNames(),
            static fn (string $name): bool => !in_array(Str::before($name, '.'), $excludedGroups, true),
        );

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    private function configuredNames(string $key): array
    {
        $names = config($key, []);

        return is_array($names)
            ? array_values(array_filter($names, is_string(...)))
            : [];
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function permissionIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $ids = Permission::query()
            ->whereIn('name', $names)
            ->where('scope', RoleScope::Tenant)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values($ids);
    }
}
