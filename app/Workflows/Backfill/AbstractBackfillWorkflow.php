<?php

declare(strict_types=1);

namespace App\Workflows\Backfill;

use App\Contracts\Backfill\BackfillInputInterface;
use App\DTOs\Backfill\BackfillOutcome;
use App\Enums\Formulas\BackfillStatus;
use App\Support\Maintenance\MaintenanceRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Exception\DataConverterException;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\TemporalFailure;
use Temporal\Workflow;
use Throwable;

abstract class AbstractBackfillWorkflow
{
    private bool $cancelled = false;

    public function failureReasonFrom(Throwable $failure): string
    {
        $cause = $failure->getPrevious() ?? $failure;

        if ($cause instanceof TemporalFailure && $cause->getOriginalMessage() !== '') {
            return $cause->getOriginalMessage();
        }

        return $cause->getMessage();
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    abstract protected function continueAsNewCall(BackfillInputInterface $input): mixed;

    /**
     * @return class-string
     */
    abstract protected function batchActivityInterface(): string;

    /**
     * @param  object  $batchActivity  a stub of {@see self::batchActivityInterface()}
     */
    abstract protected function batchCall(object $batchActivity, BackfillInputInterface $input): mixed;

    abstract protected function finalizeCall(
        BackfillInputInterface $input,
        BackfillStatus $status,
        ?string $failureReason,
    ): mixed;

    /**
     * @return Generator<mixed, mixed, mixed, mixed>
     */
    protected function process(BackfillInputInterface $input): Generator
    {
        $batch = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(10))
            ->withRetryOptions(MaintenanceRetryOptions::make()->withMaximumAttempts(max(1, $input->maxBatchAttempts)))
            ->build($this->batchActivityInterface());

        $status = BackfillStatus::Completed;
        $failureReason = null;
        $remainingBatches = $input->remainingBatches;
        $batchesRun = $input->batchesRun;
        $processedCount = $input->processedCount;
        $errorCount = $input->errorCount;
        $cursorId = $input->cursorId;
        $batchesInThisRun = 0;

        while ($remainingBatches > 0) {
            if ($this->cancelled) {
                $status = BackfillStatus::Cancelled;

                break;
            }

            if ($batchesInThisRun >= $input->checkpointBatches || Workflow::getInfo()->shouldContinueAsNew) {
                return yield $this->continueAsNewCall($input->forContinuation(
                    $remainingBatches,
                    $batchesRun,
                    $processedCount,
                    $errorCount,
                    $cursorId,
                ));
            }

            try {
                $result = yield $this->batchCall($batch, $input->withCursor($cursorId));
            } catch (ActivityFailure|DataConverterException $failure) {
                $status = BackfillStatus::Failed;
                $failureReason = $this->failureReasonFrom($failure);

                break;
            }

            $batchesRun++;
            $batchesInThisRun++;
            $remainingBatches--;
            $processedCount += $result->processedCount;
            $errorCount += $result->errorCount;
            $cursorId = $result->cursorId;

            if ($result->isCancelled) {
                $status = BackfillStatus::Cancelled;

                break;
            }

            if ($result->isExhausted) {
                break;
            }
        }

        yield $this->finalizeCall($input, $status, $failureReason);

        return new BackfillOutcome(
            status: $status,
            batchesRun: $batchesRun,
            continuations: $input->continuations,
            processedCount: $processedCount,
            errorCount: $errorCount,
        );
    }
}
