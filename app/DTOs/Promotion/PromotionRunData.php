<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class PromotionRunData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $promotionRunId,
        public string $actingUserId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            promotionRunId: (string) $data['promotion_run_id'],
            actingUserId: (string) $data['acting_user_id'],
        );
    }

    /**
     * @return array{tenant_id: string, promotion_run_id: string, acting_user_id: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'promotion_run_id' => $this->promotionRunId,
            'acting_user_id' => $this->actingUserId,
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
