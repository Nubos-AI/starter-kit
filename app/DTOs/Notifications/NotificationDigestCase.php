<?php

declare(strict_types=1);

namespace App\DTOs\Notifications;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class NotificationDigestCase implements TemporalSerializable
{
    public function __construct(public string $tenantId, public string $userId, public int $cycleStartTimestamp) {}

    /**
     * @return array{tenant_id: string, user_id: string, cycle_start: int}
     */
    public function toTemporalPayload(): array
    {
        return ['tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'cycle_start' => $this->cycleStartTimestamp];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return new self((string) $payload['tenant_id'], (string) $payload['user_id'], (int) $payload['cycle_start']);
    }
}
