<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\ProcessBulkChunkActivityInterface;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Engine\BulkChunkRunner;
use App\Support\Maintenance\MaintenanceLockRegistry;

class ProcessBulkChunkActivity implements ProcessBulkChunkActivityInterface
{
    private string $readOnlyAction = 'export-csv';

    public function __construct(
        private readonly BulkChunkRunner $runner,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>  $payload
     *
     * @throws TenantUnderMaintenanceException
     */
    public function processBulkChunk(
        string $action,
        string $tenantId,
        string $actingUserId,
        string $objectTypeId,
        array $recordIds,
        array $payload,
        string $reportKey,
    ): void {
        if ($action !== $this->readOnlyAction) {
            $this->maintenanceLocks->assertWritable($tenantId, 'bulk_action', [
                'action' => $action,
                'bulk_run_id' => $reportKey,
            ]);
        }

        $this->runner->run($action, $tenantId, $actingUserId, $objectTypeId, $recordIds, $payload, $reportKey);
    }
}
