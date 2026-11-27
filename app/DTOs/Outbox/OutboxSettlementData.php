<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class OutboxSettlementData implements TemporalSerializable
{
    /**
     * @param  list<string>  $handledIds
     * @param  list<array{entry_ids: list<string>, attempts: int}>  $failedDeliveries
     * @param  list<string>  $releasedIds
     */
    public function __construct(
        public array $handledIds,
        public array $failedDeliveries,
        public array $releasedIds,
    ) {}

    /**
     * @return array{handled_ids: list<string>, failed_deliveries: list<array{entry_ids: list<string>, attempts: int}>, released_ids: list<string>}
     */
    public function toTemporalPayload(): array
    {
        return [
            'handled_ids' => $this->handledIds,
            'failed_deliveries' => $this->failedDeliveries,
            'released_ids' => $this->releasedIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $failed = [];

        foreach (is_array($payload['failed_deliveries'] ?? null) ? $payload['failed_deliveries'] : [] as $delivery) {
            if (is_array($delivery)) {
                $failed[] = ['entry_ids' => self::strings($delivery['entry_ids'] ?? []), 'attempts' => (int) ($delivery['attempts'] ?? 0)];
            }
        }

        return new self(
            handledIds: self::strings($payload['handled_ids'] ?? []),
            failedDeliveries: $failed,
            releasedIds: self::strings($payload['released_ids'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_map(strval(...), array_filter(is_array($values) ? $values : [], is_scalar(...))));
    }
}
