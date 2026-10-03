<?php

declare(strict_types=1);

namespace App\Activities\Export;

use App\Contracts\Export\ExportRecordsActivityInterface;
use App\Support\Export\RecordExportRunner;

class ExportRecordsActivity implements ExportRecordsActivityInterface
{
    public function __construct(private readonly RecordExportRunner $runner) {}

    public function exportRecords(string $tenantId, string $actingUserId, string $exportJobId): void
    {
        $this->runner->run($tenantId, $actingUserId, $exportJobId);
    }
}
