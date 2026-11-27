<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Permission;

class PermissionOptions
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function all(): array
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->map(static fn (Permission $permission): array => [
                'value' => (string) $permission->getKey(),
                'label' => $permission->name,
            ])
            ->values()
            ->all();
    }
}
