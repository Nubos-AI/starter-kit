<?php

declare(strict_types=1);

namespace App\DTOs\Backfill;

use App\Enums\Formulas\BackfillStatus;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class BackfillOutcome implements TemporalSerializable
{
    public function __construct(
        public BackfillStatus $status,
        public int $batchesRun,
        public int $continuations,
        public int $processedCount,
        public int $errorCount,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: BackfillStatus::from((string) $data['status']),
            batchesRun: (int) ($data['batches_run'] ?? 0),
            continuations: (int) ($data['continuations'] ?? 0),
            processedCount: (int) ($data['processed_count'] ?? 0),
            errorCount: (int) ($data['error_count'] ?? 0),
        );
    }

    /**
     * @return array{status: string, batches_run: int, continuations: int, processed_count: int, error_count: int}
     */
    public function toTemporalPayload(): array
    {
        return [
            'status' => $this->status->value,
            'batches_run' => $this->batchesRun,
            'continuations' => $this->continuations,
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
