<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class RecordChangeBatch implements TemporalSerializable
{
    /**
     * @param  list<RecordChange>  $changes
     */
    public function __construct(public array $changes) {}

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
     * @return list<array{tenant_id: string, object_type_id: string, record_id: string, version: int, sequence: int, changed_field_keys: list<string>}>
     */
    public function toTemporalPayload(): array
    {
        return array_map(
            fn (RecordChange $change): array => $change->toArray(),
            $this->changes,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return self::fromArray($payload);
    }
}
