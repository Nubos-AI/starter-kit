<?php

declare(strict_types=1);

namespace App\Contracts\Formulas;

use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Formulas\FormulaBackfillData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Formulas.')]
interface BackfillFormulaBatchActivityInterface
{
    #[ActivityMethod(name: 'backfillBatch')]
    public function backfillBatch(FormulaBackfillData $input): BackfillBatchResult;
}
