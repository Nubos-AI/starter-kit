<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\Engine\RecordBackfillData;
use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Formulas\BackfillStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Engine\RecordBackfillBatchRunner;
use App\Support\Engine\RecordBackfillProgress;
use App\Support\Engine\RecordBackfillStarter;
use App\Support\Engine\RecordBackfillStrategyRegistry;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StartRecordBackfillAction
{
    public function __construct(
        private readonly RecordBackfillProgress $progress,
        private readonly RecordBackfillBatchRunner $runner,
        private readonly RecordBackfillStrategyRegistry $registry,
        private readonly RecordBackfillStarter $starter,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @throws ValidationException
     * @throws TenantUnderMaintenanceException
     */
    public function execute(string $tenantId, RecordBackfillKind $kind, string $objectTypeId): string
    {
        Validator::make(
            ['tenant_id' => $tenantId, 'object_type_id' => $objectTypeId],
            [
                'tenant_id' => ['required', 'string', Rule::exists('tenants', 'id')],
                'object_type_id' => ['required', 'string', Rule::exists('object_types', 'id')->where('tenant_id', $tenantId)],
            ]
        )->validate();

        $this->maintenanceLocks->assertWritable($tenantId, 'record_backfill', [
            'kind' => $kind->value,
            'object_type_id' => $objectTypeId,
        ]);

        $totalCount = $this->runner->countAffected($tenantId, $kind, $objectTypeId);

        $this->progress->cancelOpenRuns($tenantId, $kind, $objectTypeId);

        $userId = Auth::id();

        $run = $this->progress->open(
            $tenantId,
            $kind,
            $objectTypeId,
            $userId === null ? null : (string) $userId,
            $totalCount,
        );

        $backfillRunId = (string) $run->getKey();

        if ($totalCount === 0) {
            $this->progress->finalize($tenantId, $backfillRunId, BackfillStatus::Completed);

            return $backfillRunId;
        }

        $batchSize = max(1, $this->registry->for($kind)->batchSize());

        $input = new RecordBackfillData(
            tenantId: $tenantId,
            kind: $kind,
            objectTypeId: $objectTypeId,
            backfillRunId: $backfillRunId,
            batchSize: $batchSize,
            checkpointBatches: max(1, (int) config('engine.backfill.checkpoint_batches')),
            maxBatchAttempts: max(1, (int) config('engine.backfill.max_batch_attempts')),
            remainingBatches: (int) ceil($totalCount / $batchSize),
        );

        DB::afterCommit(fn () => $this->starter->start($input));

        return $backfillRunId;
    }
}
