<?php

declare(strict_types=1);

namespace App\Contracts\Backfill;

interface BackfillInputInterface
{
    public string $tenantId { get; }

    public string $backfillRunId { get; }

    public int $checkpointBatches { get; }

    public int $maxBatchAttempts { get; }

    public int $remainingBatches { get; }

    public int $batchesRun { get; }

    public int $continuations { get; }

    public ?string $cursorId { get; }

    public int $processedCount { get; }

    public int $errorCount { get; }

    public function withCursor(?string $cursorId): self;

    public function forContinuation(
        int $remainingBatches,
        int $batchesRun,
        int $processedCount,
        int $errorCount,
        ?string $cursorId,
    ): self;
}
