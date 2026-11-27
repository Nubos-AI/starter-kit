<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Tenancy\PurgeTenantAction;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Users\UnverifiedRegistrationDirectory;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReleaseUnverifiedRegistrationAction
{
    public function __construct(
        private readonly UnverifiedRegistrationDirectory $registrations,
        private readonly TenantDeletionGuardInterface $deletionGuard,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly PurgeTenantAction $purgeTenant,
    ) {}

    /**
     * @throws TenantPurgeFailedException
     */
    public function execute(User $registrant, RegistrationReleaseReason $reason): bool
    {
        if (!$this->isUnverifiedRegistration($registrant)) {
            return false;
        }

        $tenant = $registrant->tenant;
        $context = ['tenant_id' => $registrant->tenant_id, 'user_id' => $registrant->getKey(), 'reason' => $reason->value];

        if (!$tenant instanceof Tenant || $this->maintenanceLocks->activeFor((string) $tenant->getKey()) !== null) {
            Log::warning('An unverified registration was kept because its tenant is missing or under maintenance.', $context);

            return false;
        }

        try {
            $this->deletionGuard->assertDeletable($tenant);
        } catch (InvalidArgumentException $exception) {
            Log::warning('An unverified registration was kept because a module refused to delete its tenant.', [...$context, 'refusal' => $exception->getMessage()]);

            return false;
        }

        $this->purgeTenant->execute($tenant);

        Log::info('An unverified registration was released together with its tenant.', $context);

        return true;
    }

    private function isUnverifiedRegistration(User $user): bool
    {
        return !$user->trashed()
            && $user->status === UserStatus::Accepted
            && $user->email_verified_at === null
            && !$user->is_service
            && $user->invited_by_id === null
            && $user->tenant_id !== null
            && $this->registrations->isSoleMemberOfTenant($user);
    }
}
