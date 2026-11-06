<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Throwable;

class InstallActivityTypePermissionsAction
{
    /**
     * @throws Throwable
     */
    public function execute(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permission = Permission::query()->firstOrCreate(
                    ['name' => "activity-types.{$action}", 'scope' => RoleScope::Tenant],
                    ['group' => 'activity-types', 'is_system' => true],
                );

                if (!$permission->wasRecentlyCreated) {
                    continue;
                }

                $source = Permission::query()->where('name', "reminder-types.{$action}")->first();

                if ($source === null) {
                    continue;
                }

                if ($role->permissions()->whereKey($source->id)->exists()) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        });
    }
}
