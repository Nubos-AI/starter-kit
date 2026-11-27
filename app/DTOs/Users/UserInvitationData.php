<?php

declare(strict_types=1);

namespace App\DTOs\Users;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class UserInvitationData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $inviterName,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            userId: (string) $data['user_id'],
            inviterName: (string) $data['inviter_name'],
        );
    }

    /**
     * @return array{tenant_id: string, user_id: string, inviter_name: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'inviter_name' => $this->inviterName,
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
