<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\RecordBackfillKind;
use App\Enums\Formulas\BackfillStatus;
use App\Models\RecordBackfillRun;
use App\Support\Backfill\AbstractBackfillProgress;
use Illuminate\Database\Eloquent\Builder;

class RecordBackfillProgress extends AbstractBackfillProgress
{
    public function open(
        string $tenantId,
        RecordBackfillKind $kind,
        string $objectTypeId,
        ?string $userId,
        int $totalCount,
    ): RecordBackfillRun {
        return RecordBackfillRun::query()->create([
            'tenant_id' => $tenantId,
            'object_type_id' => $objectTypeId,
            'user_id' => $userId,
            'kind' => $kind,
            'status' => BackfillStatus::Pending,
            'total_count' => $totalCount,
        ]);
    }

    public function cancelOpenRuns(string $tenantId, RecordBackfillKind $kind, string $objectTypeId): void
    {
        RecordBackfillRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('kind', $kind->value)
            ->where('object_type_id', $objectTypeId)
            ->whereIn('status', BackfillStatus::openValues())
            ->update([
                'status' => BackfillStatus::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return Builder<RecordBackfillRun>
     */
    protected function runQuery(): Builder
    {
        return RecordBackfillRun::query();
    }

    protected function runTable(): string
    {
        return 'record_backfill_runs';
    }
}
