<?php

declare(strict_types=1);

namespace App\Contracts\Formulas;

use App\DTOs\Backfill\BackfillOutcome;
use App\DTOs\Formulas\FormulaBackfillData;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface FormulaBackfillWorkflowInterface
{
    #[WorkflowMethod(name: 'FormulaBackfillWorkflow')]
    #[ReturnType(BackfillOutcome::class)]
    public function run(FormulaBackfillData $input): Generator;

    #[SignalMethod]
    public function cancel(): void;
}
