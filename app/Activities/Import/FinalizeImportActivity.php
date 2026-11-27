<?php

declare(strict_types=1);

namespace App\Activities\Import;

use App\Actions\Import\FinalizeImportAction;
use App\Actions\Import\NotifyImportCompletedAction;
use App\Contracts\Import\FinalizeImportActivityInterface;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\ActingUserContext;

class FinalizeImportActivity implements FinalizeImportActivityInterface
{
    public function __construct(
        private readonly FinalizeImportAction $finalizeImport,
        private readonly NotifyImportCompletedAction $notifyImportCompleted,
        private readonly ActingUserContext $context,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function finalizeImport(string $tenantId, string $actingUserId, string $importJobId, bool $failed = false): bool
    {
        return $this->steps->run('import.finalize', ['tenant_id' => $tenantId, 'import_job_id' => $importJobId], function () use ($tenantId, $actingUserId, $importJobId, $failed): bool {
            $isCompleted = false;

            $this->context->run($tenantId, $actingUserId, function () use ($tenantId, $importJobId, $failed, &$isCompleted): void {
                $isCompleted = $this->finalizeImport->execute($tenantId, $importJobId, $failed);
            });

            return $isCompleted;
        });
    }

    public function notifyImportCompleted(string $tenantId, string $actingUserId, string $importJobId): void
    {
        $this->steps->run('import.notify-completed', ['tenant_id' => $tenantId, 'import_job_id' => $importJobId], function () use ($tenantId, $actingUserId, $importJobId): void {
            $this->context->run($tenantId, $actingUserId, fn () => $this->notifyImportCompleted->execute($importJobId));
        });
    }
}
