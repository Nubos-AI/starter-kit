<?php

declare(strict_types=1);

namespace App\Activities\Import;

use App\Actions\Import\ProcessImportChunkAction;
use App\Contracts\Import\ProcessImportChunkActivityInterface;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Maintenance\MaintenanceLockRegistry;

class ProcessImportChunkActivity implements ProcessImportChunkActivityInterface
{
    public function __construct(
        private readonly ProcessImportChunkAction $processor,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws TenantUnderMaintenanceException
     */
    public function processImportChunk(
        string $tenantId,
        string $actingUserId,
        string $importJobId,
        string $disk,
        string $path,
        string $format,
        ?string $sheet,
        int $offset,
        int $limit,
    ): void {
        $this->maintenanceLocks->assertWritable($tenantId, 'import', [
            'import_job_id' => $importJobId,
            'offset' => $offset,
        ]);

        $this->processor->execute($tenantId, $actingUserId, $importJobId, $disk, $path, $format, $sheet, $offset, $limit);
    }
}
