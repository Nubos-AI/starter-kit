<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Contracts\Import\ImportDispatcherInterface;
use App\Models\ImportJob;

class SynchronousImportDispatcher implements ImportDispatcherInterface
{
    public function __construct(
        private readonly ImportChunkProcessor $processor,
        private readonly ImportFinalizer $finalizer,
    ) {}

    public function start(ImportJob $importJob, ?string $sheet, int $chunkSize): void
    {
        $tenantId = $importJob->tenant_id;
        $actingUserId = $importJob->user_id;
        $importJobId = (string) $importJob->getKey();
        $disk = $importJob->source_disk;
        $path = $importJob->source_path;
        $format = $importJob->format;
        $total = $importJob->total_rows;

        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $this->processor->process($tenantId, $actingUserId, $importJobId, $disk, $path, $format, $sheet, $offset, $chunkSize);
        }

        $this->finalizer->finalize($tenantId, $actingUserId, $importJobId);
    }
}
