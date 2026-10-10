<?php

declare(strict_types=1);

namespace App\Actions\Formulas;

use App\DTOs\Formulas\FormulaBackfillData;
use App\Enums\Formulas\BackfillStatus;
use App\Models\FieldDefinition;
use App\Models\FormulaBackfillRun;
use App\Support\Formulas\FormulaBackfillBatchRunner;
use App\Support\Formulas\FormulaBackfillProgress;
use App\Support\Formulas\FormulaBackfillStarter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StartFormulaBackfillAction
{
    public function __construct(
        private readonly FormulaBackfillProgress $progress,
        private readonly FormulaBackfillBatchRunner $runner,
        private readonly FormulaBackfillStarter $starter,
    ) {}

    public function execute(FieldDefinition $field): ?FormulaBackfillRun
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return null;
        }

        $fieldDefinitionId = (string) $field->getKey();
        $objectTypeId = $field->object_type_id;
        $totalCount = $this->runner->countAffected($tenantId, $objectTypeId);

        $this->progress->cancelOpenRuns($tenantId, $fieldDefinitionId);

        $userId = Auth::id();

        $run = $this->progress->open(
            $tenantId,
            $fieldDefinitionId,
            $objectTypeId,
            $userId === null ? null : (string) $userId,
            $totalCount,
        );

        $backfillRunId = (string) $run->getKey();

        if ($totalCount === 0) {
            if (!$this->progress->finalize($tenantId, $backfillRunId, BackfillStatus::Completed)) {
                Log::warning('Formula backfill run was already closed before the empty run could be finalized.', [
                    'backfill_run_id' => $backfillRunId,
                    'tenant_id' => $tenantId,
                ]);
            }

            return $run->refresh();
        }

        $batchSize = max(1, (int) config('formulas.backfill_batch_size'));

        $input = new FormulaBackfillData(
            tenantId: $tenantId,
            fieldDefinitionId: $fieldDefinitionId,
            objectTypeId: $objectTypeId,
            backfillRunId: $backfillRunId,
            batchSize: $batchSize,
            checkpointBatches: max(1, (int) config('formulas.backfill_checkpoint_batches')),
            remainingBatches: (int) ceil($totalCount / $batchSize),
            maxBatchAttempts: max(1, (int) config('formulas.backfill_max_batch_attempts')),
        );

        DB::afterCommit(fn () => $this->starter->start($input));

        return $run;
    }
}
