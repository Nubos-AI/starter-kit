<?php

declare(strict_types=1);

namespace App\DTOs\Backfill;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class BackfillBatchResult implements TemporalSerializable
{
    public function __construct(
        public int $processedCount,
        public int $errorCount,
        public ?string $cursorId,
        public bool $isExhausted = false,
        public bool $isCancelled = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $cursorId = $data['cursor_id'] ?? null;

        return new self(
            processedCount: (int) ($data['processed_count'] ?? 0),
            errorCount: (int) ($data['error_count'] ?? 0),
            cursorId: $cursorId === null ? null : (string) $cursorId,
            isExhausted: (bool) ($data['is_exhausted'] ?? false),
            isCancelled: (bool) ($data['is_cancelled'] ?? false),
        );
    }

    /**
     * @return array{processed_count: int, error_count: int, cursor_id: string|null, is_exhausted: bool, is_cancelled: bool}
     */
    public function toTemporalPayload(): array
    {
        return [
            'processed_count' => $this->processedCount,
            'error_count' => $this->errorCount,
            'cursor_id' => $this->cursorId,
            'is_exhausted' => $this->isExhausted,
            'is_cancelled' => $this->isCancelled,
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
