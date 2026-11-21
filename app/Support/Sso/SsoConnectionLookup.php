<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\SsoConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SsoConnectionLookup
{
    public function enabledForDomain(string $domain): ?SsoConnection
    {
        $domain = Str::lower($domain);

        return SsoConnection::withoutTenantScope()
            ->where('sso_connections.is_enabled', true)
            ->whereJsonContains('sso_connections.allowed_domains', $domain)
            ->whereHas('verifiedDomains', static fn (Builder $query): Builder => $query->where('sso_verified_domains.domain', $domain))
            ->with('verifiedDomains')
            ->first();
    }

    public function forTenant(string $tenantId): ?SsoConnection
    {
        return SsoConnection::withoutTenantScope()
            ->where('sso_connections.tenant_id', $tenantId)
            ->first();
    }
}
