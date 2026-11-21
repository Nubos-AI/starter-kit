<?php

declare(strict_types=1);

use App\DTOs\Backfill\BackfillOutcome;
use App\DTOs\Formulas\FormulaBackfillData;
use App\Enums\Formulas\BackfillStatus;
use App\Workflows\Formulas\FormulaBackfillWorkflow;
use Temporal\Exception\DataConverterException;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(int, int):FormulaBackfillData */
    $this->backfillInput = fn (int $remainingBatches, int $checkpointBatches = 25): FormulaBackfillData => new FormulaBackfillData(
        tenantId: ModelStub::ulid('backfill-tenant'),
        fieldDefinitionId: ModelStub::ulid('backfill-field'),
        objectTypeId: ModelStub::ulid('backfill-object-type'),
        backfillRunId: ModelStub::ulid('backfill-run'),
        batchSize: 200,
        checkpointBatches: $checkpointBatches,
        remainingBatches: $remainingBatches,
    );
});

it('carries the counters of the finished checkpoint into the continuation and counts it', function (): void {
    $continued = ($this->backfillInput)(60)->forContinuation(35, 25, 5000, 3, ModelStub::ulid('cursor'));

    expect($continued->remainingBatches)->toBe(35)
        ->and($continued->batchesRun)->toBe(25)
        ->and($continued->processedCount)->toBe(5000)
        ->and($continued->errorCount)->toBe(3)
        ->and($continued->cursorId)->toBe(ModelStub::ulid('cursor'))
        ->and($continued->continuations)->toBe(1);
});

it('keeps counting continuations across several checkpoint windows', function (): void {
    $input = ($this->backfillInput)(60);

    $third = $input
        ->forContinuation(35, 25, 5000, 0, null)
        ->forContinuation(10, 50, 10000, 0, null)
        ->forContinuation(0, 60, 12000, 0, null);

    expect($third->continuations)->toBe(3)
        ->and($third->batchesRun)->toBe(60)
        ->and($third->remainingBatches)->toBe(0);
});

it('leaves the identity of the backfill untouched when it continues as new', function (): void {
    $input = ($this->backfillInput)(60, 25);

    $continued = $input->forContinuation(35, 25, 5000, 0, null);

    expect($continued->tenantId)->toBe($input->tenantId)
        ->and($continued->fieldDefinitionId)->toBe($input->fieldDefinitionId)
        ->and($continued->objectTypeId)->toBe($input->objectTypeId)
        ->and($continued->backfillRunId)->toBe($input->backfillRunId)
        ->and($continued->batchSize)->toBe($input->batchSize)
        ->and($continued->checkpointBatches)->toBe($input->checkpointBatches)
        ->and($continued->maxBatchAttempts)->toBe($input->maxBatchAttempts);
});

it('hands the next batch the cursor of the previous one and changes nothing else', function (): void {
    $input = ($this->backfillInput)(5);

    $next = $input->withCursor(ModelStub::ulid('cursor'));

    expect($next->cursorId)->toBe(ModelStub::ulid('cursor'))
        ->and($next->remainingBatches)->toBe($input->remainingBatches)
        ->and($next->batchesRun)->toBe($input->batchesRun)
        ->and($next->continuations)->toBe($input->continuations)
        ->and($next->processedCount)->toBe($input->processedCount)
        ->and($next->errorCount)->toBe($input->errorCount);
});

it('survives the round trip through the temporal payload without losing a counter', function (): void {
    $input = ($this->backfillInput)(60)->forContinuation(35, 25, 5000, 3, ModelStub::ulid('cursor'));

    expect(FormulaBackfillData::fromTemporalPayload($input->toTemporalPayload()))->toEqual($input);
});

it('reports the outcome of a backfill through the same payload keys the workflow returns', function (): void {
    $outcome = new BackfillOutcome(BackfillStatus::Cancelled, 12, 2, 2400, 1);

    expect(BackfillOutcome::fromTemporalPayload($outcome->toTemporalPayload()))->toEqual($outcome)
        ->and($outcome->toTemporalPayload()['status'])->toBe('cancelled');
});

it('names the original message of the cause as the reason a wrapped activity failed', function (): void {
    $cause = new ApplicationFailure(
        'record 01J0 could not be materialized',
        RuntimeException::class,
        false,
    );

    $reason = (new FormulaBackfillWorkflow)->failureReasonFrom(
        new ActivityFailure(7, 8, 'Formulas.backfillBatch', 'activity-1', 1, 'worker@host', $cause),
    );

    expect($reason)->toBe('record 01J0 could not be materialized');
});

it('falls back to the wrapping failure when it carries no cause', function (): void {
    $failure = new ActivityFailure(7, 8, 'Formulas.backfillBatch', 'activity-1', 1, 'worker@host');

    $reason = (new FormulaBackfillWorkflow)->failureReasonFrom($failure);

    expect($reason)->toBe($failure->getMessage())
        ->and($reason)->toContain('Formulas.backfillBatch');
});

it('names the plain message of a cause that is not a temporal failure', function (): void {
    $reason = (new FormulaBackfillWorkflow)->failureReasonFrom(
        new DataConverterException('payload of type string is not a BackfillBatchResult'),
    );

    expect($reason)->toBe('payload of type string is not a BackfillBatchResult');
});
