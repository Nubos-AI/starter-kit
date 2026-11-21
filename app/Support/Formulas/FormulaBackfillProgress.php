<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Enums\Formulas\BackfillStatus;
use App\Models\FormulaBackfillRun;
use App\Support\Backfill\AbstractBackfillProgress;
use Illuminate\Database\Eloquent\Builder;

class FormulaBackfillProgress extends AbstractBackfillProgress
{
    public function open(
        string $tenantId,
        string $fieldDefinitionId,
        string $objectTypeId,
        ?string $userId,
        int $totalCount,
    ): FormulaBackfillRun {
        return FormulaBackfillRun::query()->create([
            'tenant_id' => $tenantId,
            'field_definition_id' => $fieldDefinitionId,
            'object_type_id' => $objectTypeId,
            'user_id' => $userId,
            'status' => BackfillStatus::Pending,
            'total_count' => $totalCount,
        ]);
    }

    public function cancelOpenRuns(string $tenantId, string $fieldDefinitionId): void
    {
        FormulaBackfillRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('field_definition_id', $fieldDefinitionId)
            ->whereIn('status', BackfillStatus::openValues())
            ->update([
                'status' => BackfillStatus::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return Builder<FormulaBackfillRun>
     */
    protected function runQuery(): Builder
    {
        return FormulaBackfillRun::query();
    }

    protected function runTable(): string
    {
        return 'formula_backfill_runs';
    }
}
