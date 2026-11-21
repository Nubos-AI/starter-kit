<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class BulkActionData implements TemporalSerializable
{
    /**
     * @param  list<list<string>>  $chunks
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $bulkRunId,
        public string $tenantId,
        public string $actingUserId,
        public string $objectTypeId,
        public string $action,
        public array $chunks,
        public array $payload,
        public int $threshold,
        public string $objectTypeSlug,
        public bool $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            bulkRunId: (string) $data['bulk_run_id'],
            tenantId: (string) $data['tenant_id'],
            actingUserId: (string) $data['acting_user_id'],
            objectTypeId: (string) $data['object_type_id'],
            action: (string) $data['action'],
            chunks: array_values(array_map(
                fn (mixed $chunk): array => array_values(array_map('strval', (array) $chunk)),
                (array) ($data['chunks'] ?? []),
            )),
            payload: (array) ($data['payload'] ?? []),
            threshold: (int) $data['threshold'],
            objectTypeSlug: (string) $data['object_type_slug'],
            notify: (bool) $data['notify'],
        );
    }

    /**
     * @return array{bulk_run_id: string, tenant_id: string, acting_user_id: string, object_type_id: string, action: string, chunks: list<list<string>>, payload: array<string, mixed>, threshold: int, object_type_slug: string, notify: bool}
     */
    public function toTemporalPayload(): array
    {
        return [
            'bulk_run_id' => $this->bulkRunId,
            'tenant_id' => $this->tenantId,
            'acting_user_id' => $this->actingUserId,
            'object_type_id' => $this->objectTypeId,
            'action' => $this->action,
            'chunks' => $this->chunks,
            'payload' => $this->payload,
            'threshold' => $this->threshold,
            'object_type_slug' => $this->objectTypeSlug,
            'notify' => $this->notify,
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
