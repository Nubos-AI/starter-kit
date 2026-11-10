<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\Enums\Formulas\BackfillStatus;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'RecordBackfill.')]
interface FinalizeRecordBackfillActivityInterface
{
    #[ActivityMethod(name: 'finalizeRecordBackfill')]
    public function finalizeRecordBackfill(
        string $tenantId,
        string $backfillRunId,
        BackfillStatus $status,
        ?string $failureReason = null,
    ): void;
}
