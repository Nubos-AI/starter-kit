<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\BackfillRecordBatchActivityInterface;
use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Engine\RecordBackfillData;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Engine\RecordBackfillBatchRunner;
use App\Support\Maintenance\MaintenanceLockRegistry;

class BackfillRecordBatchActivity implements BackfillRecordBatchActivityInterface
{
    public function __construct(
        private readonly RecordBackfillBatchRunner $runner,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws TenantUnderMaintenanceException
     */
    public function backfillBatch(RecordBackfillData $input): BackfillBatchResult
    {
        $this->maintenanceLocks->assertWritable($input->tenantId, 'record_backfill', [
            'backfill_run_id' => $input->backfillRunId,
        ]);

        return $this->runner->runBatch($input);
    }
}
