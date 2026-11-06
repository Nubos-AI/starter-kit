<?php

declare(strict_types=1);

namespace App\Support\Maintenance;

use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\MaintenanceLock;

class MaintenanceLockRegistry
{
    /**
     * @var list<string>
     */
    public static array $exemptRouteNames = [
        'engine.maintenance.destroy',
    ];

    /**
     * @var list<string>
     */
    public static array $nonWritingRouteNames = [
        'engine.records.grid',
        'engine.records.relations.entries',
        'engine.records.relations.candidates',
        'engine.records.merge.preview',
        'reports.preview',
        'notification-rules.preview',
        'dashboards.widgets.results.index',
        'dashboards.widgets.results.show',
        'engine.import.preview',
        'logout',
        'password.confirm.store',
    ];

    public function activeFor(string $tenantId): ?MaintenanceLock
    {
        return MaintenanceLock::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('status', MaintenanceLockStatus::Active)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TenantUnderMaintenanceException
     */
    public function assertWritable(string $tenantId, string $entryPoint, array $context = []): void
    {
        if ($this->activeFor($tenantId) === null) {
            return;
        }

        throw TenantUnderMaintenanceException::writeRefused($tenantId);
    }

    /**
     * @return list<string>
     */
    public function lockedTenantIds(): array
    {
        return array_values(
            MaintenanceLock::withoutTenantScope()
                ->where('status', MaintenanceLockStatus::Active)
                ->pluck('tenant_id')
                ->map(fn (mixed $tenantId): string => (string) $tenantId)
                ->all(),
        );
    }
}
