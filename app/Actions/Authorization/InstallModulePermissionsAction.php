<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class InstallModulePermissionsAction
{
    private string $roleAdministrationPermission = 'roles.update';

    public function __construct(
        private readonly TenantBinder $tenants,
        private readonly PermissionCatalog $catalog,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(string $group): void
    {
        $actions = $this->catalog->globalGroups()[$group] ?? [];

        if ($actions === []) {
            throw new LogicException("Unknown permission group [{$group}].");
        }

        Tenant::query()->each(function (Tenant $tenant) use ($group, $actions): void {
            $this->tenants->runWith($tenant, fn () => $this->installInCurrentTenant($group, $actions));
        });
    }

    /**
     * @param  list<string>  $actions
     *
     * @throws Throwable
     */
    private function installInCurrentTenant(string $group, array $actions): void
    {
        DB::transaction(function () use ($group, $actions): void {
            $permissionIds = [];

            foreach ($actions as $action) {
                $permissionIds[] = (string) Permission::query()->firstOrCreate(
                    ['name' => "{$group}.{$action}", 'scope' => RoleScope::Tenant],
                    ['group' => $group, 'is_system' => true],
                )->getKey();
            }

            $roles = Role::query()
                ->whereHas(
                    'permissions',
                    fn (Builder $query): Builder => $query->where('name', $this->roleAdministrationPermission),
                )
                ->get();

            foreach ($roles as $role) {
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }
        });
    }
}
