<?php

declare(strict_types=1);

namespace App\Activities\Reports;

use App\Contracts\Reports\ExportReportActivityInterface;
use App\Support\Export\ReportExportRunner;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportReportActivity implements ExportReportActivityInterface
{
    public function __construct(private readonly ReportExportRunner $runner) {}

    /**
     * @throws Throwable
     */
    public function exportReport(string $tenantId, string $actingUserId, string $exportJobId): void
    {
        $context = [
            'tenant_id' => $tenantId,
            'acting_user_id' => $actingUserId,
            'export_job_id' => $exportJobId,
        ];

        try {
            $this->runner->run($tenantId, $actingUserId, $exportJobId);
        } catch (Throwable $exception) {
            Log::error('Report export failed.', [...$context, 'exception' => $exception::class]);

            throw $exception;
        }
    }

    public function finalizeReportExport(string $tenantId, string $exportJobId): void
    {
        $status = $this->runner->finalize($tenantId, $exportJobId);

        $context = [
            'tenant_id' => $tenantId,
            'export_job_id' => $exportJobId,
        ];

        if ($status === null) {
            Log::warning('Report export finalization found no export job.', $context);

            return;
        }
    }
}
