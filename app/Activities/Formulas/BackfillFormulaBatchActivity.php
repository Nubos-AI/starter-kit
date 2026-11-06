<?php

declare(strict_types=1);

namespace App\Activities\Formulas;

use App\Contracts\Formulas\BackfillFormulaBatchActivityInterface;
use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Formulas\FormulaBackfillData;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Formulas\FormulaBackfillBatchRunner;
use App\Support\Maintenance\MaintenanceLockRegistry;

class BackfillFormulaBatchActivity implements BackfillFormulaBatchActivityInterface
{
    public function __construct(
        private readonly FormulaBackfillBatchRunner $runner,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws TenantUnderMaintenanceException
     */
    public function backfillBatch(FormulaBackfillData $input): BackfillBatchResult
    {
        $this->maintenanceLocks->assertWritable($input->tenantId, 'formula_backfill', [
            'backfill_run_id' => $input->backfillRunId,
        ]);

        return $this->runner->runBatch($input);
    }
}
