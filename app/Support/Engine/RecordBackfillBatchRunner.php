<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Engine\RecordBackfillData;
use App\Enums\Engine\RecordBackfillKind;
use App\Models\CustomRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class RecordBackfillBatchRunner
{
    public function __construct(
        private readonly RecordBackfillStrategyRegistry $registry,
        private readonly RecordBackfillProgress $progress,
    ) {}

    public function countAffected(string $tenantId, RecordBackfillKind $kind, string $objectTypeId): int
    {
        return TenantContext::withTenantId(
            $tenantId,
            fn (): int => $this->baseQuery($this->registry->for($kind), $tenantId, $objectTypeId)->count(),
        ) ?? 0;
    }

    public function runBatch(RecordBackfillData $input): BackfillBatchResult
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

    private function processBatch(RecordBackfillData $input): BackfillBatchResult
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

        $strategy = $this->registry->for($input->kind);

        $records = $this->batchQuery($strategy, $input)->get();

        if ($records->isEmpty()) {
            return new BackfillBatchResult(
                processedCount: 0,
                errorCount: 0,
                cursorId: $input->cursorId,
                isExhausted: true,
            );
        }

        $errorCount = $strategy->applyBatch($input->tenantId, $records);

        $cursorId = (string) $records->last()->getKey();

        $advanced = $this->progress->advance(
            $input->tenantId,
            $input->backfillRunId,
            $input->cursorId,
            $cursorId,
            $records->count(),
            $errorCount,
        );

        $isExhausted = $records->count() < $input->batchSize;

        return new BackfillBatchResult(
            processedCount: $advanced ? $records->count() : 0,
            errorCount: $advanced ? $errorCount : 0,
            cursorId: $cursorId,
            isExhausted: $isExhausted,
        );
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function baseQuery(RecordBackfillStrategyInterface $strategy, string $tenantId, string $objectTypeId): Builder
    {
        $query = CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ofType($objectTypeId);

        $strategy->constrainQuery($query);

        return $query;
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function batchQuery(RecordBackfillStrategyInterface $strategy, RecordBackfillData $input): Builder
    {
        $query = $this->baseQuery($strategy, $input->tenantId, $input->objectTypeId);

        if ($input->cursorId !== null) {
            $query->where('id', '>', $input->cursorId);
        }

        return $query
            ->orderBy('id')
            ->limit($input->batchSize);
    }
}
