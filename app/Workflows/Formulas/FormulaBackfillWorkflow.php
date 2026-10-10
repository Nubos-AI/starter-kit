<?php

declare(strict_types=1);

namespace App\Workflows\Formulas;

use App\Contracts\Backfill\BackfillInputInterface;
use App\Contracts\Formulas\BackfillFormulaBatchActivityInterface;
use App\Contracts\Formulas\FinalizeFormulaBackfillActivityInterface;
use App\Contracts\Formulas\FormulaBackfillWorkflowInterface;
use App\DTOs\Formulas\FormulaBackfillData;
use App\Enums\Formulas\BackfillStatus;
use App\Workflows\Backfill\AbstractBackfillWorkflow;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Workflow;

class FormulaBackfillWorkflow extends AbstractBackfillWorkflow implements FormulaBackfillWorkflowInterface
{
    public function run(FormulaBackfillData $input): Generator
    {
        return yield from $this->process($input);
    }

    protected function continueAsNewCall(BackfillInputInterface $input): mixed
    {
        /** @var FormulaBackfillData $input */
        return Workflow::newContinueAsNewStub(FormulaBackfillWorkflowInterface::class)->run($input);
    }

    protected function batchActivityInterface(): string
    {
        return BackfillFormulaBatchActivityInterface::class;
    }

    protected function batchCall(object $batchActivity, BackfillInputInterface $input): mixed
    {
        /** @var BackfillFormulaBatchActivityInterface $batchActivity */
        /** @var FormulaBackfillData $input */
        return $batchActivity->backfillBatch($input);
    }

    protected function finalizeCall(
        BackfillInputInterface $input,
        BackfillStatus $status,
        ?string $failureReason,
    ): mixed {
        $finalize = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(5))
            ->build(FinalizeFormulaBackfillActivityInterface::class);

        return $finalize->finalizeFormulaBackfill($input->tenantId, $input->backfillRunId, $status, $failureReason);
    }
}
