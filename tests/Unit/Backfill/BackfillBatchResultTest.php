<?php

declare(strict_types=1);

use App\DTOs\Backfill\BackfillBatchResult;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('carries the batch counters and the cursor through the temporal payload unchanged', function (): void {
    $result = new BackfillBatchResult(processedCount: 12, errorCount: 1, cursorId: ModelStub::ulid('cursor'), isExhausted: false);

    $restored = BackfillBatchResult::fromTemporalPayload($result->toTemporalPayload());

    expect($restored->processedCount)->toBe(12)
        ->and($restored->errorCount)->toBe(1)
        ->and($restored->cursorId)->toBe($result->cursorId)
        ->and($restored->isExhausted)->toBeFalse()
        ->and($restored->isCancelled)->toBeFalse();
});
