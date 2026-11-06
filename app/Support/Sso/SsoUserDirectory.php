<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Enums\Sso\IdentityProvider;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SsoUserDirectory
{
    public function byIdentity(string $tenantId, IdentityProvider $provider, string $issuer, string $subject): ?User
    {
        return User::query()
            ->where('users.tenant_id', $tenantId)
            ->where('users.is_service', false)
            ->whereHas('identities', static function (Builder $identities) use ($tenantId, $provider, $issuer, $subject): void {
                $identities
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('user_identities.tenant_id', $tenantId)
                    ->where('provider', $provider->value)
                    ->where('issuer', $issuer)
                    ->where('subject', $subject);
            })
            ->first();
    }

    public function byEmail(string $email): ?User
    {
        return User::withTrashed()
            ->where('email', Str::lower(trim($email)))
            ->where('is_service', false)
            ->first();
    }
}
