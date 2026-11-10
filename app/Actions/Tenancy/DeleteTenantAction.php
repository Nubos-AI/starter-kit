<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\DTOs\Tenancy\PurgeReport;
use App\Enums\Authorization\RoleAuthority;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class DeleteTenantAction
{
    private string $deletionPermission = 'tenants.delete';

    public function __construct(
        private readonly PurgeTenantAction $purgeTenant,
        private readonly TenantDeletionGuardInterface $deletionGuard,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws TenantPurgeFailedException
     * @throws TenantUnderMaintenanceException
     */
    public function execute(Tenant $tenant, User $actor): PurgeReport
    {
        if (!$this->mayDelete($actor, $tenant)) {
            throw new AuthorizationException(__('i18n.backend.actions.tenancy.delete_tenant_action.only_the_owner_or_a_holder_of_the_deletion_permission_may_delete_a_tenant'));
        }

        $this->deletionGuard->assertDeletable($tenant);

        $this->maintenanceLocks->assertWritable((string) $tenant->getKey(), 'tenant_deletion', [
            'acting_user_id' => (string) $actor->getKey(),
        ]);

        return $this->purgeTenant->execute($tenant);
    }

    private function mayDelete(User $actor, Tenant $tenant): bool
    {
        return $actor->hasRoleWithAuthority(RoleAuthority::SuperAdmin, $tenant)
            || $actor->hasRoleWithAuthority(RoleAuthority::ScopeAdmin, $tenant)
            || $actor->hasPermission($this->deletionPermission, $tenant);
    }
}
