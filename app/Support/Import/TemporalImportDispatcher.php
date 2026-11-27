<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Contracts\Import\ImportDispatcherInterface;
use App\Contracts\Import\ImportWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Import\ImportData;
use App\DTOs\Temporal\ProcessStartData;
use App\Models\ImportJob;

class TemporalImportDispatcher implements ImportDispatcherInterface
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function start(ImportJob $importJob, ?string $sheet, int $chunkSize): void
    {
        $tenantId = $importJob->tenant_id;
        $importJobId = (string) $importJob->getKey();

        $this->processes->start(new ProcessStartData(
            workflow: ImportWorkflowInterface::class,
            workflowId: "import:{$tenantId}:{$importJobId}",
            arguments: [new ImportData(
                tenantId: $tenantId,
                actingUserId: $importJob->user_id,
                importJobId: $importJobId,
                disk: $importJob->source_disk,
                path: $importJob->source_path,
                format: $importJob->format,
                sheet: $sheet,
                totalRows: $importJob->total_rows,
                chunkSize: $chunkSize,
            )],
        ));
    }
}
