<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Contracts\Backfill\BackfillInputInterface;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class FormulaBackfillData implements BackfillInputInterface, TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $fieldDefinitionId,
        public string $objectTypeId,
        public string $backfillRunId,
        public int $batchSize,
        public int $checkpointBatches = 25,
        public int $remainingBatches = 0,
        public int $batchesRun = 0,
        public int $continuations = 0,
        public ?string $cursorId = null,
        public int $maxBatchAttempts = 3,
        public int $processedCount = 0,
        public int $errorCount = 0,
    ) {}

    public function withCursor(?string $cursorId): self
    {
        return new self(
            tenantId: $this->tenantId,
            fieldDefinitionId: $this->fieldDefinitionId,
            objectTypeId: $this->objectTypeId,
            backfillRunId: $this->backfillRunId,
            batchSize: $this->batchSize,
            checkpointBatches: $this->checkpointBatches,
            remainingBatches: $this->remainingBatches,
            batchesRun: $this->batchesRun,
            continuations: $this->continuations,
            cursorId: $cursorId,
            maxBatchAttempts: $this->maxBatchAttempts,
            processedCount: $this->processedCount,
            errorCount: $this->errorCount,
        );
    }

    public function forContinuation(
        int $remainingBatches,
        int $batchesRun,
        int $processedCount,
        int $errorCount,
        ?string $cursorId,
    ): self {
        return new self(
            tenantId: $this->tenantId,
            fieldDefinitionId: $this->fieldDefinitionId,
            objectTypeId: $this->objectTypeId,
            backfillRunId: $this->backfillRunId,
            batchSize: $this->batchSize,
            checkpointBatches: $this->checkpointBatches,
            remainingBatches: $remainingBatches,
            batchesRun: $batchesRun,
            continuations: $this->continuations + 1,
            cursorId: $cursorId,
            maxBatchAttempts: $this->maxBatchAttempts,
            processedCount: $processedCount,
            errorCount: $errorCount,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $cursorId = $data['cursor_id'] ?? null;

        return new self(
            tenantId: (string) $data['tenant_id'],
            fieldDefinitionId: (string) $data['field_definition_id'],
            objectTypeId: (string) $data['object_type_id'],
            backfillRunId: (string) $data['backfill_run_id'],
            batchSize: (int) $data['batch_size'],
            checkpointBatches: (int) ($data['checkpoint_batches'] ?? 25),
            remainingBatches: (int) ($data['remaining_batches'] ?? 0),
            batchesRun: (int) ($data['batches_run'] ?? 0),
            continuations: (int) ($data['continuations'] ?? 0),
            cursorId: $cursorId === null ? null : (string) $cursorId,
            maxBatchAttempts: (int) ($data['max_batch_attempts'] ?? 3),
            processedCount: (int) ($data['processed_count'] ?? 0),
            errorCount: (int) ($data['error_count'] ?? 0),
        );
    }

    /**
     * @return array{tenant_id: string, field_definition_id: string, object_type_id: string, backfill_run_id: string, batch_size: int, checkpoint_batches: int, remaining_batches: int, batches_run: int, continuations: int, cursor_id: string|null, max_batch_attempts: int, processed_count: int, error_count: int}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'field_definition_id' => $this->fieldDefinitionId,
            'object_type_id' => $this->objectTypeId,
            'backfill_run_id' => $this->backfillRunId,
            'batch_size' => $this->batchSize,
            'checkpoint_batches' => $this->checkpointBatches,
            'remaining_batches' => $this->remainingBatches,
            'batches_run' => $this->batchesRun,
            'continuations' => $this->continuations,
            'cursor_id' => $this->cursorId,
            'max_batch_attempts' => $this->maxBatchAttempts,
            'processed_count' => $this->processedCount,
            'error_count' => $this->errorCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return self::fromArray($payload);
    }
}
