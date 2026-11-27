<?php

declare(strict_types=1);

namespace App\DTOs\Webhooks;

use App\Support\Temporal\ProcessSeal;
use Illuminate\Contracts\Encryption\Encrypter;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class WebhookDeliveryData implements TemporalSerializable
{
    public function __construct(
        public string $subscriptionId,
        public string $tenantId,
        public string $eventId,
        public string $eventType,
        public int $sequence,
        public string $sealedBody,
    ) {}

    public static function sealed(Encrypter $encrypter, string $subscriptionId, string $tenantId, string $eventId, string $eventType, int $sequence, string $rawBody): self
    {
        return new self($subscriptionId, $tenantId, $eventId, $eventType, $sequence, ProcessSeal::seal($encrypter, self::binding($subscriptionId, $tenantId, $eventId), $rawBody));
    }

    public function unsealedBody(Encrypter $encrypter): string
    {
        return ProcessSeal::unseal($encrypter, self::binding($this->subscriptionId, $this->tenantId, $this->eventId), $this->sealedBody);
    }

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
            sealedBody: (string) $data['sealed_body'],
        );
    }

    /**
     * @return array{subscription_id: string, tenant_id: string, event_id: string, event_type: string, sequence: int, sealed_body: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'sequence' => $this->sequence,
            'sealed_body' => $this->sealedBody,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return self::fromArray($payload);
    }

    /**
     * @return array{subscription_id: string, tenant_id: string, event_id: string}
     */
    private static function binding(string $subscriptionId, string $tenantId, string $eventId): array
    {
        return ['subscription_id' => $subscriptionId, 'tenant_id' => $tenantId, 'event_id' => $eventId];
    }
}
