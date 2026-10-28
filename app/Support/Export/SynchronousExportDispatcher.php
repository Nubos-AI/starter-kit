<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Contracts\Export\ExportDispatcherInterface;
use App\Models\ExportJob;

class SynchronousExportDispatcher implements ExportDispatcherInterface
{
    public function __construct(
        private readonly RecordExportRunner $runner,
        private readonly ExportFinalizer $finalizer,
    ) {}

    public function start(ExportJob $exportJob): void
    {
        $tenantId = $exportJob->tenant_id;
        $userId = $exportJob->user_id;
        $exportJobId = (string) $exportJob->getKey();

        try {
            $this->runner->run($tenantId, $userId, $exportJobId);
        } finally {
            $this->finalizer->finalize($tenantId, $userId, $exportJobId);
        }
    }
}
