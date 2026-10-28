<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Contracts\Import\ImportDispatcherInterface;
use App\Contracts\Import\ImportWorkflowInterface;
use App\DTOs\Import\ImportData;
use App\Models\ImportJob;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

class TemporalImportDispatcher implements ImportDispatcherInterface
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function start(ImportJob $importJob, ?string $sheet, int $chunkSize): void
    {
        $tenantId = $importJob->tenant_id;
        $importJobId = (string) $importJob->getKey();

        $stub = $this->workflowClient->newWorkflowStub(
            ImportWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("import:{$tenantId}:{$importJobId}")
                ->withTaskQueue((string) config('temporal.queue')),
        );

        $this->workflowClient->start($stub, new ImportData(
            tenantId: $tenantId,
            actingUserId: $importJob->user_id,
            importJobId: $importJobId,
            disk: $importJob->source_disk,
            path: $importJob->source_path,
            format: $importJob->format,
            sheet: $sheet,
            totalRows: $importJob->total_rows,
            chunkSize: $chunkSize,
        ));
    }
}
