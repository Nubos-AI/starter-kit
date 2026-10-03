<?php

declare(strict_types=1);

namespace App\Support\Backfill;

use App\Enums\Formulas\BackfillStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class AbstractBackfillProgress
{
    public function statusOf(string $tenantId, string $backfillRunId): ?BackfillStatus
    {
        $status = $this->runQuery()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($backfillRunId)
            ->value('status');

        return $status instanceof BackfillStatus ? $status : null;
    }

    public function advance(
        string $tenantId,
        string $backfillRunId,
        ?string $previousCursorId,
        string $cursorId,
        int $processedCount,
        int $errorCount,
    ): bool {
        $table = $this->runTable();

        $affected = DB::update(
            <<<SQL
                UPDATE {$table}
                SET status = ?,
                    processed_count = processed_count + ?,
                    error_count = error_count + ?,
                    cursor_id = ?,
                    updated_at = ?
                WHERE id = ?
                  AND tenant_id = ?
                  AND status IN (?, ?)
                  AND cursor_id IS NOT DISTINCT FROM ?
                SQL,
            [
                BackfillStatus::Running->value,
                $processedCount,
                $errorCount,
                $cursorId,
                now(),
                $backfillRunId,
                $tenantId,
                BackfillStatus::Pending->value,
                BackfillStatus::Running->value,
                $previousCursorId,
            ],
        );

        return $affected > 0;
    }

    public function finalize(string $tenantId, string $backfillRunId, BackfillStatus $status): bool
    {
        $affected = $this->runQuery()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($backfillRunId)
            ->whereIn('status', BackfillStatus::openValues())
            ->update([
                'status' => $status->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    /**
     * @return Builder<covariant Model>
     */
    abstract protected function runQuery(): Builder;

    /**
     * @return literal-string
     */
    abstract protected function runTable(): string;
}
