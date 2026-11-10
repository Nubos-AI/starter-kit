<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Contracts\Export\ExportDispatcherInterface;
use App\Contracts\Export\ExportWorkflowInterface;
use App\DTOs\Export\ExportData;
use App\Models\ExportJob;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

class TemporalExportDispatcher implements ExportDispatcherInterface
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function start(ExportJob $exportJob): void
    {
        $tenantId = $exportJob->tenant_id;
        $userId = $exportJob->user_id;
        $exportJobId = (string) $exportJob->getKey();

        $stub = $this->workflowClient->newWorkflowStub(
            ExportWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("export:{$tenantId}:{$exportJobId}")
                ->withTaskQueue((string) config('temporal.queue')),
        );

        $this->workflowClient->start($stub, new ExportData(
            tenantId: $tenantId,
            actingUserId: $userId,
            exportJobId: $exportJobId,
        ));
    }
}
