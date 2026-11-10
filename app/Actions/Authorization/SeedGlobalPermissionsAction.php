<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Permission;
use App\Support\Authorization\PermissionCatalog;

class SeedGlobalPermissionsAction
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function execute(): void
    {
        foreach ($this->catalog->globalGroups() as $group => $actions) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate(
                    ['name' => "{$group}.{$action}", 'scope' => RoleScope::Tenant],
                    ['group' => $group, 'is_system' => true],
                );
            }
        }
    }
}
