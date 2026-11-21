<?php

declare(strict_types=1);

use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\BulkFinalizer;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');

    $this->batchId = ModelStub::ulid('bulk-finalizer-batch');
    $this->tenantId = ModelStub::ulid('bulk-finalizer-tenant');
    $this->userId = ModelStub::ulid('bulk-finalizer-user');

    BulkBatchReport::initProgress($this->batchId, $this->tenantId, $this->userId, 3);
});

it('reports every record of a failed chunk with a German reason and counts the failed chunks', function (): void {
    $failedChunks = [
        [ModelStub::ulid('bulk-finalizer-a'), ModelStub::ulid('bulk-finalizer-b')],
        [ModelStub::ulid('bulk-finalizer-c')],
    ];

    app(BulkFinalizer::class)->finalize($this->tenantId, $this->userId, $this->batchId, [], 10, 'companies', false, $failedChunks);

    $message = __('i18n.backend.support.engine.bulk_chunk_runner.the_record_could_not_be_processed_please_try_again');

    expect($message)->not->toStartWith('i18n.')
        ->and(BulkBatchReport::errors($this->batchId))->toBe([
            ['recordId' => ModelStub::ulid('bulk-finalizer-a'), 'message' => $message],
            ['recordId' => ModelStub::ulid('bulk-finalizer-b'), 'message' => $message],
            ['recordId' => ModelStub::ulid('bulk-finalizer-c'), 'message' => $message],
        ])
        ->and(BulkBatchReport::progress($this->batchId))->toMatchArray(['failed' => 2, 'finished' => true]);
});

it('reports no failure when every chunk succeeded', function (): void {
    app(BulkFinalizer::class)->finalize($this->tenantId, $this->userId, $this->batchId, [], 10, 'companies', false);

    expect(BulkBatchReport::errors($this->batchId))->toBe([])
        ->and(BulkBatchReport::progress($this->batchId))->toMatchArray(['failed' => 0, 'finished' => true]);
});

it('reports the failed chunks only once when Temporal retries the finalize activity after a later step failed', function (): void {
    $failedChunks = [[ModelStub::ulid('bulk-finalizer-a')], [ModelStub::ulid('bulk-finalizer-b')]];

    app(BulkFinalizer::class)->finalize($this->tenantId, $this->userId, $this->batchId, [], 10, 'companies', false, $failedChunks);
    app(BulkFinalizer::class)->finalize($this->tenantId, $this->userId, $this->batchId, [], 10, 'companies', false, $failedChunks);

    expect(BulkBatchReport::errors($this->batchId))->toHaveCount(2)
        ->and(BulkBatchReport::progress($this->batchId))->toMatchArray(['failed' => 2, 'finished' => true]);
});
