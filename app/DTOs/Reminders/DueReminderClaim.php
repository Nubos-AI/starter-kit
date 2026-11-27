<?php

declare(strict_types=1);

namespace App\DTOs\Reminders;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class DueReminderClaim implements TemporalSerializable
{
    /**
     * @param  list<string>  $pendingChannels
     */
    public function __construct(
        public bool $isClaimed,
        public ?string $claimedAt,
        public array $pendingChannels,
    ) {}

    public static function unclaimed(): self
    {
        return new self(false, null, []);
    }

    /**
     * @return array{is_claimed: bool, claimed_at: string|null, pending_channels: list<string>}
     */
    public function toTemporalPayload(): array
    {
        return ['is_claimed' => $this->isClaimed, 'claimed_at' => $this->claimedAt, 'pending_channels' => $this->pendingChannels];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $channels = is_array($payload['pending_channels'] ?? null) ? $payload['pending_channels'] : [];

        return new self(
            (bool) ($payload['is_claimed'] ?? false),
            is_string($payload['claimed_at'] ?? null) ? $payload['claimed_at'] : null,
            array_values(array_map(strval(...), array_filter($channels, is_string(...)))),
        );
    }
}
