<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;

class TenantUserIdResolver
{
    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function resolve(string $tenantId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_service', false)
            ->whereKey($userIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values($ids);
    }
}
