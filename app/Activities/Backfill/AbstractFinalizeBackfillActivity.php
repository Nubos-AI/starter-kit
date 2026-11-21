<?php

declare(strict_types=1);

namespace App\Activities\Backfill;

use App\Enums\Formulas\BackfillStatus;
use App\Support\Backfill\AbstractBackfillProgress;
use Illuminate\Support\Facades\Log;

abstract class AbstractFinalizeBackfillActivity
{
    public function __construct(protected readonly AbstractBackfillProgress $progress) {}

    abstract protected function logSubject(): string;

    protected function finalizeRun(
        string $tenantId,
        string $backfillRunId,
        BackfillStatus $status,
        ?string $failureReason,
    ): void {
        $transitioned = $this->progress->finalize($tenantId, $backfillRunId, $status);

        $context = [
            'backfill_run_id' => $backfillRunId,
            'tenant_id' => $tenantId,
            'status' => $status->value,
            'transitioned' => $transitioned,
        ];

        if ($status === BackfillStatus::Failed) {
            Log::error("{$this->logSubject()} failed.", [...$context, 'failure_reason' => $failureReason]);

            return;
        }
    }
}
