<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

readonly class RecordChange
{
    /**
     * @param  list<string>  $changedFieldKeys
     */
    public function __construct(
        public string $tenantId,
        public string $objectTypeId,
        public string $recordId,
        public int $version,
        public int $sequence,
        public array $changedFieldKeys,
        public ?string $triggeredByAutomationId = null,
        public ?string $rootRunId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            objectTypeId: (string) $data['object_type_id'],
            recordId: (string) $data['record_id'],
            version: (int) ($data['version'] ?? 0),
            sequence: (int) ($data['sequence'] ?? 0),
            changedFieldKeys: array_values(array_map('strval', (array) ($data['changed_field_keys'] ?? []))),
            triggeredByAutomationId: self::optionalId($data, 'triggered_by_automation_id'),
            rootRunId: self::optionalId($data, 'root_run_id'),
        );
    }

    /**
     * @return array{tenant_id: string, object_type_id: string, record_id: string, version: int, sequence: int, changed_field_keys: list<string>, triggered_by_automation_id: string|null, root_run_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'record_id' => $this->recordId,
            'version' => $this->version,
            'sequence' => $this->sequence,
            'changed_field_keys' => $this->changedFieldKeys,
            'triggered_by_automation_id' => $this->triggeredByAutomationId,
            'root_run_id' => $this->rootRunId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalId(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
