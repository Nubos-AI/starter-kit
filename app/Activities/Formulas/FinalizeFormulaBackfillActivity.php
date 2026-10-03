<?php

declare(strict_types=1);

namespace App\Activities\Formulas;

use App\Activities\Backfill\AbstractFinalizeBackfillActivity;
use App\Contracts\Formulas\FinalizeFormulaBackfillActivityInterface;
use App\Enums\Formulas\BackfillStatus;
use App\Support\Formulas\FormulaBackfillProgress;

class FinalizeFormulaBackfillActivity extends AbstractFinalizeBackfillActivity implements FinalizeFormulaBackfillActivityInterface
{
    public function __construct(FormulaBackfillProgress $progress)
    {
        parent::__construct($progress);
    }

    public function finalizeFormulaBackfill(
        string $tenantId,
        string $backfillRunId,
        BackfillStatus $status,
        ?string $failureReason = null,
    ): void {
        $this->finalizeRun($tenantId, $backfillRunId, $status, $failureReason);
    }

    protected function logSubject(): string
    {
        return __('i18n.backend.activities.formulas.finalize_formula_backfill_activity.formula_backfill');
    }
}
