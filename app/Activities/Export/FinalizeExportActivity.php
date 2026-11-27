<?php

declare(strict_types=1);

namespace App\Activities\Export;

use App\Actions\Export\FinalizeExportAction;
use App\Actions\Export\NotifyExportCompletedAction;
use App\Contracts\Export\FinalizeExportActivityInterface;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class FinalizeExportActivity implements FinalizeExportActivityInterface
{
    public function __construct(
        private readonly FinalizeExportAction $finalizeExport,
        private readonly NotifyExportCompletedAction $notifyExportCompleted,
        private readonly TenantBinder $tenantBinder,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function finalizeExport(string $tenantId, string $userId, string $exportJobId): bool
    {
        return $this->steps->run('export.finalize', ['tenant_id' => $tenantId, 'export_job_id' => $exportJobId], fn (): bool => (bool) $this->tenantBinder->runIfKnown(
            $tenantId,
            fn (): bool => $this->finalizeExport->execute($exportJobId),
        ));
    }

    public function notifyExportCompleted(string $tenantId, string $userId, string $exportJobId): void
    {
        $this->steps->run('export.notify-completed', ['tenant_id' => $tenantId, 'export_job_id' => $exportJobId], fn () => $this->tenantBinder->runIfKnown(
            $tenantId,
            fn () => $this->notifyExportCompleted->execute($userId, $exportJobId),
        ));
    }
}
