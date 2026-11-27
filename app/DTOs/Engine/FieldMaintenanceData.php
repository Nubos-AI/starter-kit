<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class FieldMaintenanceData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $fieldDefinitionId,
        public bool $syncIndexes,
        public bool $backfillRollups,
    ) {}

    /**
     * @return array{tenant_id: string, field_definition_id: string, sync_indexes: bool, backfill_rollups: bool}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'field_definition_id' => $this->fieldDefinitionId,
            'sync_indexes' => $this->syncIndexes,
            'backfill_rollups' => $this->backfillRollups,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return new self(
            tenantId: (string) $payload['tenant_id'],
            fieldDefinitionId: (string) $payload['field_definition_id'],
            syncIndexes: (bool) ($payload['sync_indexes'] ?? false),
            backfillRollups: (bool) ($payload['backfill_rollups'] ?? false),
        );
    }
}
