<?php

declare(strict_types=1);

namespace App\DTOs\Webhooks;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class WebhookDeliveryData implements TemporalSerializable
{
    public function __construct(
        public string $subscriptionId,
        public string $tenantId,
        public string $eventId,
        public string $eventType,
        public int $sequence,
        public string $rawBody,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subscriptionId: (string) $data['subscription_id'],
            tenantId: (string) $data['tenant_id'],
            eventId: (string) $data['event_id'],
            eventType: (string) $data['event_type'],
            sequence: (int) $data['sequence'],
            rawBody: (string) $data['raw_body'],
        );
    }

    /**
     * @return array{subscription_id: string, tenant_id: string, event_id: string, event_type: string, sequence: int, raw_body: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'sequence' => $this->sequence,
            'raw_body' => $this->rawBody,
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
