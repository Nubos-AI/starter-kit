<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\MaintenanceLock;
use App\Support\Maintenance\MaintenanceLockRegistry;

class StaticMaintenanceLockRegistry extends MaintenanceLockRegistry
{
    /**
     * @param  list<string>  $lockedTenantIds
     */
    public function __construct(private array $lockedTenantIds = []) {}

    public function activeFor(string $tenantId): ?MaintenanceLock
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function assertWritable(string $tenantId, string $entryPoint, array $context = []): void
    {
        if (in_array($tenantId, $this->lockedTenantIds, true)) {
            throw TenantUnderMaintenanceException::writeRefused($tenantId);
        }
    }

    /**
     * @return list<string>
     */
    public function lockedTenantIds(): array
    {
        return $this->lockedTenantIds;
    }
}
