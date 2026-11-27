<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;
use UnexpectedValueException;

readonly class RecordChangeBatch implements TemporalSerializable
{
    /**
     * @param  list<RecordChange>  $changes
     */
    public function __construct(public array $changes, public ?string $relayBatchId = null) {}

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            fn (array $change): RecordChange => RecordChange::fromArray($change),
            $data,
        ));
    }

    /**
     * @return list<array<string, mixed>>|array{relay_batch_id: string, changes: list<array<string, mixed>>}
     */
    public function toTemporalPayload(): array
    {
        $changes = array_map(
            fn (RecordChange $change): array => $change->toArray(),
            $this->changes,
        );

        return $this->relayBatchId === null
            ? $changes
            : ['relay_batch_id' => $this->relayBatchId, 'changes' => $changes];
    }

    /**
     * @param  list<array<string, mixed>>|array{relay_batch_id: string, changes: list<array<string, mixed>>}  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        if (isset($payload['relay_batch_id']) && is_string($payload['relay_batch_id']) && is_array($payload['changes'])) {
            return new self(array_map(self::recordOf(...), array_values($payload['changes'])), $payload['relay_batch_id']);
        }

        return new self(array_map(self::recordOf(...), array_values($payload)));
    }

    private static function recordOf(mixed $value): RecordChange
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException('A record change payload must be an object.');
        }

        $attributes = [];

        foreach ($value as $key => $attribute) {
            if (is_string($key)) {
                $attributes[$key] = $attribute;
            }
        }

        return RecordChange::fromArray($attributes);
    }
}
