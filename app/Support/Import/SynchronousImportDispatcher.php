<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Actions\Import\FinalizeImportAction;
use App\Actions\Import\ProcessImportChunkAction;
use App\Contracts\Import\ImportDispatcherInterface;
use App\Models\ImportJob;
use Throwable;

class SynchronousImportDispatcher implements ImportDispatcherInterface
{
    public function __construct(
        private readonly ProcessImportChunkAction $processor,
        private readonly FinalizeImportAction $finalizer,
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

        try {
            for ($offset = 0; $offset < $total; $offset += $chunkSize) {
                $this->processor->execute($tenantId, $actingUserId, $importJobId, $disk, $path, $format, $sheet, $offset, $chunkSize);
            }
        } catch (Throwable $exception) {
            $this->finalizer->execute($tenantId, $actingUserId, $importJobId, failed: true);

            throw $exception;
        }

        $this->finalizer->execute($tenantId, $actingUserId, $importJobId);
    }
}
