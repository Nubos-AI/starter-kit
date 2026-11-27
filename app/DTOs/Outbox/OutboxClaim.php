<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class OutboxClaim implements TemporalSerializable
{
    /**
     * @param  list<OutboxDelivery>  $deliveries
     */
    public function __construct(
        public array $deliveries,
        public int $leaseDeadlineTimestamp,
        public bool $hasMore,
        public int $setAsideCount,
    ) {}

    public function isEmpty(): bool
    {
        return $this->deliveries === [];
    }

    /**
     * @return array{deliveries: list<array<string, mixed>>, lease_deadline: int, has_more: bool, set_aside: int}
     */
    public function toTemporalPayload(): array
    {
        return [
            'deliveries' => array_map(static fn (OutboxDelivery $delivery): array => $delivery->toTemporalPayload(), $this->deliveries),
            'lease_deadline' => $this->leaseDeadlineTimestamp,
            'has_more' => $this->hasMore,
            'set_aside' => $this->setAsideCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $deliveries = [];

        foreach (is_array($payload['deliveries'] ?? null) ? $payload['deliveries'] : [] as $delivery) {
            if (is_array($delivery)) {
                /** @var array<string, mixed> $delivery */
                $deliveries[] = OutboxDelivery::fromTemporalPayload($delivery);
            }
        }

        return new self(
            deliveries: $deliveries,
            leaseDeadlineTimestamp: (int) ($payload['lease_deadline'] ?? 0),
            hasMore: (bool) ($payload['has_more'] ?? false),
            setAsideCount: (int) ($payload['set_aside'] ?? 0),
        );
    }
}
