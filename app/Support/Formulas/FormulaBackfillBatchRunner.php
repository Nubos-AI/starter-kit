<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Formulas\FormulaBackfillData;
use App\DTOs\Formulas\FormulaErrorValue;
use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class FormulaBackfillBatchRunner
{
    public function __construct(
        private readonly ComputedFieldHandler $computedFieldHandler,
        private readonly FormulaBackfillProgress $progress,
    ) {}

    public function countAffected(string $tenantId, string $objectTypeId): int
    {
        return $this->baseQuery($tenantId, $objectTypeId)->count();
    }

    public function runBatch(FormulaBackfillData $input): BackfillBatchResult
    {
        return TenantContext::withTenantId(
            $input->tenantId,
            fn (): BackfillBatchResult => $this->processBatch($input),
        ) ?? new BackfillBatchResult(
            processedCount: 0,
            errorCount: 0,
            cursorId: null,
            isExhausted: true,
        );
    }

    private function processBatch(FormulaBackfillData $input): BackfillBatchResult
    {
        $status = $this->progress->statusOf($input->tenantId, $input->backfillRunId);

        if ($status === null || !$status->isOpen()) {
            return new BackfillBatchResult(
                processedCount: 0,
                errorCount: 0,
                cursorId: $input->cursorId,
                isCancelled: true,
            );
        }

        $field = FieldDefinition::query()->whereKey($input->fieldDefinitionId)->first();

        if (!$field instanceof FieldDefinition) {
            return new BackfillBatchResult(
                processedCount: 0,
                errorCount: 0,
                cursorId: $input->cursorId,
                isExhausted: true,
            );
        }

        $records = $this->batchQuery($input)->get();

        if ($records->isEmpty()) {
            return new BackfillBatchResult(
                processedCount: 0,
                errorCount: 0,
                cursorId: $input->cursorId,
                isExhausted: true,
            );
        }

        $errorCount = 0;

        foreach ($records as $record) {
            if ($this->computedFieldHandler->materialize($field, $record) instanceof FormulaErrorValue) {
                $errorCount++;
            }
        }

        $cursorId = (string) $records->last()->getKey();

        $advanced = $this->progress->advance(
            $input->tenantId,
            $input->backfillRunId,
            $input->cursorId,
            $cursorId,
            $records->count(),
            $errorCount,
        );

        return new BackfillBatchResult(
            processedCount: $advanced ? $records->count() : 0,
            errorCount: $advanced ? $errorCount : 0,
            cursorId: $cursorId,
            isExhausted: $records->count() < $input->batchSize,
        );
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function baseQuery(string $tenantId, string $objectTypeId): Builder
    {
        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ofType($objectTypeId)
            ->whereNull('deleted_at');
    }

    /**
     * @return Builder<CustomRecord>
     */
    public function batchQuery(FormulaBackfillData $input): Builder
    {
        $query = $this->baseQuery($input->tenantId, $input->objectTypeId);

        if ($input->cursorId !== null) {
            $query->where('id', '>', $input->cursorId);
        }

        return $query
            ->orderBy('id')
            ->limit($input->batchSize);
    }
}
