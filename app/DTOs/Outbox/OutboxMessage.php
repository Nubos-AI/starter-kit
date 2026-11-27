<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class OutboxMessage implements TemporalSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $topic,
        public string $tenantId,
        public OutboxSubject $subject,
        public array $payload,
        public int $attempts,
    ) {}

    /**
     * @return array{topic: string, tenant_id: string, subject_type: string, subject_id: string, payload: array<string, mixed>, attempts: int}
     */
    public function toTemporalPayload(): array
    {
        return [
            'topic' => $this->topic,
            'tenant_id' => $this->tenantId,
            'subject_type' => $this->subject->type,
            'subject_id' => $this->subject->id,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        /** @var array<string, mixed> $data */
        return new self(
            topic: (string) $payload['topic'],
            tenantId: (string) $payload['tenant_id'],
            subject: new OutboxSubject((string) $payload['subject_type'], (string) $payload['subject_id']),
            payload: $data,
            attempts: (int) $payload['attempts'],
        );
    }
}
