<?php

declare(strict_types=1);

namespace App\DTOs\Export;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ExportData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $actingUserId,
        public string $exportJobId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            actingUserId: (string) $data['acting_user_id'],
            exportJobId: (string) $data['export_job_id'],
        );
    }

    /**
     * @return array{tenant_id: string, acting_user_id: string, export_job_id: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'acting_user_id' => $this->actingUserId,
            'export_job_id' => $this->exportJobId,
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
