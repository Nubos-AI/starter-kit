<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Role;

class TenantRoleOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function assignable(): array
    {
        return array_values(
            Role::query()
                ->whereNull('authority')
                ->orderBy('name')
                ->get()
                ->map(static fn (Role $role): array => [
                    'value' => (string) $role->getKey(),
                    'label' => $role->name,
                ])
                ->all(),
        );
    }
}
