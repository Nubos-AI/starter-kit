<?php

declare(strict_types=1);

namespace App\DTOs\Notifications;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class NotificationDigestClaim implements TemporalSerializable
{
    public function __construct(
        public bool $isClaimed,
        public ?string $stateId,
        public ?string $sentAt,
        public ?string $previousSentAt,
        public ?string $collectedSince,
    ) {}

    public static function unclaimed(): self
    {
        return new self(false, null, null, null, null);
    }

    /**
     * @return array{is_claimed: bool, state_id: string|null, sent_at: string|null, previous_sent_at: string|null, collected_since: string|null}
     */
    public function toTemporalPayload(): array
    {
        return [
            'is_claimed' => $this->isClaimed,
            'state_id' => $this->stateId,
            'sent_at' => $this->sentAt,
            'previous_sent_at' => $this->previousSentAt,
            'collected_since' => $this->collectedSince,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $text = static fn (string $key): ?string => is_string($payload[$key] ?? null) ? $payload[$key] : null;

        return new self((bool) ($payload['is_claimed'] ?? false), $text('state_id'), $text('sent_at'), $text('previous_sent_at'), $text('collected_since'));
    }
}
