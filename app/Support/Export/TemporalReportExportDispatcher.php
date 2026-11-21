<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Contracts\Reports\ExportReportWorkflowInterface;
use App\DTOs\Export\ExportData;
use App\Models\ExportJob;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

class TemporalReportExportDispatcher
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function start(ExportJob $exportJob): void
    {
        $tenantId = $exportJob->tenant_id;
        $exportJobId = (string) $exportJob->getKey();

        $stub = $this->workflowClient->newWorkflowStub(
            ExportReportWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("report-export:{$tenantId}:{$exportJobId}")
                ->withTaskQueue((string) config('temporal.queue')),
        );

        $this->workflowClient->start($stub, new ExportData(
            tenantId: $tenantId,
            actingUserId: $exportJob->user_id,
            exportJobId: $exportJobId,
        ));
    }
}
