<?php

declare(strict_types=1);

namespace App\Contracts\Formulas;

use App\Enums\Formulas\BackfillStatus;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Formulas.')]
interface FinalizeFormulaBackfillActivityInterface
{
    #[ActivityMethod(name: 'finalizeFormulaBackfill')]
    public function finalizeFormulaBackfill(
        string $tenantId,
        string $backfillRunId,
        BackfillStatus $status,
        ?string $failureReason = null,
    ): void;
}
