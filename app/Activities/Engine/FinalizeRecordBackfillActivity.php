<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Activities\Backfill\AbstractFinalizeBackfillActivity;
use App\Contracts\Engine\FinalizeRecordBackfillActivityInterface;
use App\Enums\Formulas\BackfillStatus;
use App\Support\Engine\RecordBackfillProgress;

class FinalizeRecordBackfillActivity extends AbstractFinalizeBackfillActivity implements FinalizeRecordBackfillActivityInterface
{
    public function __construct(RecordBackfillProgress $progress)
    {
        parent::__construct($progress);
    }

    public function finalizeRecordBackfill(
        string $tenantId,
        string $backfillRunId,
        BackfillStatus $status,
        ?string $failureReason = null,
    ): void {
        $this->finalizeRun($tenantId, $backfillRunId, $status, $failureReason);
    }

    protected function logSubject(): string
    {
        return __('i18n.backend.activities.engine.finalize_record_backfill_activity.record_backfill');
    }
}
