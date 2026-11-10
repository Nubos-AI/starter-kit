<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Tenancy\TenantBinder;

class SsoEnforcementGate
{
    public function __construct(
        private readonly SsoConnectionLookup $connections,
        private readonly TenantBinder $tenants,
    ) {}

    public function blocksLocalLogin(User $user): bool
    {
        return $this->enforcingConnection($user) instanceof SsoConnection;
    }

    public function releasesEnforcedEmail(User $user, string $email): bool
    {
        $connection = $this->enforcingConnection($user);

        return $connection instanceof SsoConnection && !$connection->claimsEmail($email);
    }

    private function enforcingConnection(User $user): ?SsoConnection
    {
        if ($user->tenant_id === null) {
            return null;
        }

        $connection = $this->connections->forTenant($user->tenant_id);

        if (!$connection instanceof SsoConnection || !$connection->is_enabled || !$connection->is_enforced) {
            return null;
        }

        if (!$connection->claimsEmail($user->email)) {
            return null;
        }

        $isEmergencyAccess = $this->tenants->runIfKnown($user->tenant_id, static fn (): bool => $user->isEscalatedAuthority());

        return $isEmergencyAccess === true ? null : $connection;
    }
}
