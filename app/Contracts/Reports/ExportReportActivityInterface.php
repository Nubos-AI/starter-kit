<?php

declare(strict_types=1);

namespace App\Contracts\Reports;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'ExportReport.')]
interface ExportReportActivityInterface
{
    #[ActivityMethod(name: 'exportReport')]
    public function exportReport(string $tenantId, string $actingUserId, string $exportJobId): void;

    #[ActivityMethod(name: 'finalizeReportExport')]
    public function finalizeReportExport(string $tenantId, string $exportJobId): void;
}
