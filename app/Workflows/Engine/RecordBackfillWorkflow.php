<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Backfill\BackfillInputInterface;
use App\Contracts\Engine\BackfillRecordBatchActivityInterface;
use App\Contracts\Engine\FinalizeRecordBackfillActivityInterface;
use App\Contracts\Engine\RecordBackfillWorkflowInterface;
use App\DTOs\Engine\RecordBackfillData;
use App\Enums\Formulas\BackfillStatus;
use App\Workflows\Backfill\AbstractBackfillWorkflow;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Workflow;

class RecordBackfillWorkflow extends AbstractBackfillWorkflow implements RecordBackfillWorkflowInterface
{
    public function run(RecordBackfillData $input): Generator
    {
        return yield from $this->process($input);
    }

    protected function continueAsNewCall(BackfillInputInterface $input): mixed
    {
        /** @var RecordBackfillData $input */
        return Workflow::newContinueAsNewStub(RecordBackfillWorkflowInterface::class)->run($input);
    }

    protected function batchActivityInterface(): string
    {
        return BackfillRecordBatchActivityInterface::class;
    }

    protected function batchCall(object $batchActivity, BackfillInputInterface $input): mixed
    {
        /** @var BackfillRecordBatchActivityInterface $batchActivity */
        /** @var RecordBackfillData $input */
        return $batchActivity->backfillBatch($input);
    }

    protected function finalizeCall(
        BackfillInputInterface $input,
        BackfillStatus $status,
        ?string $failureReason,
    ): mixed {
        $finalize = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(5))
            ->build(FinalizeRecordBackfillActivityInterface::class);

        return $finalize->finalizeRecordBackfill($input->tenantId, $input->backfillRunId, $status, $failureReason);
    }
}
