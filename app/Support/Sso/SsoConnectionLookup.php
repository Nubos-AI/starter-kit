<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\SsoConnection;
use Illuminate\Support\Str;

class SsoConnectionLookup
{
    public function enabledForDomain(string $domain): ?SsoConnection
    {
        $domain = Str::lower($domain);

        return SsoConnection::withoutTenantScope()
            ->where('sso_connections.is_enabled', true)
            ->whereJsonContains('sso_connections.allowed_domains', $domain)
            ->whereJsonContains('sso_connections.verified_domains', $domain)
            ->first();
    }

    public function forTenant(string $tenantId): ?SsoConnection
    {
        return SsoConnection::withoutTenantScope()
            ->where('sso_connections.tenant_id', $tenantId)
            ->first();
    }
}
