<?php

declare(strict_types=1);

namespace App\DTOs\Import;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ImportData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $actingUserId,
        public string $importJobId,
        public string $disk,
        public string $path,
        public string $format,
        public ?string $sheet,
        public int $totalRows,
        public int $chunkSize,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            actingUserId: (string) $data['acting_user_id'],
            importJobId: (string) $data['import_job_id'],
            disk: (string) $data['disk'],
            path: (string) $data['path'],
            format: (string) $data['format'],
            sheet: isset($data['sheet']) ? (string) $data['sheet'] : null,
            totalRows: (int) $data['total_rows'],
            chunkSize: (int) $data['chunk_size'],
        );
    }

    /**
     * @return array{tenant_id: string, acting_user_id: string, import_job_id: string, disk: string, path: string, format: string, sheet: string|null, total_rows: int, chunk_size: int}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'acting_user_id' => $this->actingUserId,
            'import_job_id' => $this->importJobId,
            'disk' => $this->disk,
            'path' => $this->path,
            'format' => $this->format,
            'sheet' => $this->sheet,
            'total_rows' => $this->totalRows,
            'chunk_size' => $this->chunkSize,
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
