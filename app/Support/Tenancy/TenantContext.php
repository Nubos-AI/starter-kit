<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;

class TenantContext
{
    public static function current(): ?Tenant
    {
        if (!app()->bound('current_tenant')) {
            return null;
        }

        $tenant = app('current_tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public static function currentId(?string $fallback = null): ?string
    {
        $tenant = self::current();

        return $tenant !== null ? (string) $tenant->getKey() : $fallback;
    }

    public static function withTenantId(string $tenantId, Closure $callback): mixed
    {
        return app(TenantBinder::class)->runIfKnown($tenantId, fn (): mixed => $callback());
    }
}
