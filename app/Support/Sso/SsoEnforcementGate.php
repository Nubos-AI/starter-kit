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

    public function blocksLocalLoginThrough(SsoConnection $connection, User $user): bool
    {
        if (!$connection->is_enabled || !$connection->is_enforced || !$connection->claimsEmail($user->email)) {
            return false;
        }

        $isEmergencyAccess = $this->tenants->runIfKnown($connection->tenant_id, static fn (): bool => $user->isEscalatedAuthority());

        return $isEmergencyAccess !== true;
    }

    private function enforcingConnection(User $user): ?SsoConnection
    {
        if ($user->tenant_id === null) {
            return null;
        }

        $connection = $this->connections->forTenant($user->tenant_id);

        return $connection instanceof SsoConnection && $this->blocksLocalLoginThrough($connection, $user) ? $connection : null;
    }
}
