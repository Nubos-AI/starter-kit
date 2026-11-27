<?php

declare(strict_types=1);

namespace App\DTOs\Reminders;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class DueReminderCase implements TemporalSerializable
{
    public function __construct(public string $tenantId, public string $reminderId) {}

    /**
     * @return array{tenant_id: string, reminder_id: string}
     */
    public function toTemporalPayload(): array
    {
        return ['tenant_id' => $this->tenantId, 'reminder_id' => $this->reminderId];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return new self((string) $payload['tenant_id'], (string) $payload['reminder_id']);
    }
}
