<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\RecomputeRollupActivityInterface;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Engine\RollupRecomputer;
use App\Support\Maintenance\MaintenanceLockRegistry;

class RecomputeRollupActivity implements RecomputeRollupActivityInterface
{
    public function __construct(
        private readonly RollupRecomputer $recomputer,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     *
     * @throws TenantUnderMaintenanceException
     */
    public function recomputeRollup(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys): void
    {
        $this->maintenanceLocks->assertWritable($tenantId, 'rollup_recompute', ['record_id' => $recordId]);

        $this->recomputer->recompute($tenantId, $objectTypeId, $recordId, $changedFieldKeys);
    }

    /**
     * @throws TenantUnderMaintenanceException
     */
    public function recomputeOwnRollups(string $tenantId, string $objectTypeId, string $recordId): void
    {
        $this->maintenanceLocks->assertWritable($tenantId, 'rollup_recompute', ['record_id' => $recordId]);

        $this->recomputer->recomputeOwn($tenantId, $objectTypeId, $recordId);
    }
}
