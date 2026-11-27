<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ObjectTypePurgeData implements TemporalSerializable
{
    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function __construct(
        public string $tenantId,
        public string $objectTypeId,
        public array $files,
    ) {}

    /**
     * @return array{tenant_id: string, object_type_id: string, files: list<array{disk: string, path: string}>}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'files' => $this->files,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $files = [];

        foreach ((array) ($payload['files'] ?? []) as $file) {
            if (is_array($file) && isset($file['disk'], $file['path'])) {
                $files[] = ['disk' => (string) $file['disk'], 'path' => (string) $file['path']];
            }
        }

        return new self((string) $payload['tenant_id'], (string) $payload['object_type_id'], $files);
    }
}
