<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Contracts\Reports\ExportReportWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Export\ExportData;
use App\DTOs\Temporal\ProcessStartData;
use App\Models\ExportJob;

class TemporalReportExportDispatcher
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function start(ExportJob $exportJob): void
    {
        $tenantId = $exportJob->tenant_id;
        $exportJobId = (string) $exportJob->getKey();

        $this->processes->start(new ProcessStartData(
            workflow: ExportReportWorkflowInterface::class,
            workflowId: "report-export:{$tenantId}:{$exportJobId}",
            arguments: [new ExportData(
                tenantId: $tenantId,
                actingUserId: $exportJob->user_id,
                exportJobId: $exportJobId,
            )],
        ));
    }
}
