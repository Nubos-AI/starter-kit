<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Actions\Engine\FinalizeBulkRunAction;
use App\Actions\Engine\NotifyBulkRecipientsAction;
use App\Contracts\Engine\FinalizeBulkActivityInterface;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\ActingUserContext;

class FinalizeBulkActivity implements FinalizeBulkActivityInterface
{
    public function __construct(
        private readonly FinalizeBulkRunAction $finalizeBulkRun,
        private readonly NotifyBulkRecipientsAction $notifyBulkRecipients,
        private readonly ActingUserContext $context,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function finalizeBulk(string $reportKey, array $failedChunks = []): void
    {
        $this->steps->run('bulk.finalize', ['bulk_run_id' => $reportKey, 'failed_chunks' => count($failedChunks)], fn () => $this->finalizeBulkRun->execute($reportKey, $failedChunks));
    }

    public function notifyBulkRecipients(string $tenantId, string $actingUserId, array $affectedIds, string $objectTypeSlug): void
    {
        $this->steps->run('bulk.notify-recipients', ['tenant_id' => $tenantId, 'record_count' => count($affectedIds)], function () use ($tenantId, $actingUserId, $affectedIds, $objectTypeSlug): void {
            $this->context->run($tenantId, $actingUserId, fn () => $this->notifyBulkRecipients->execute($affectedIds, $objectTypeSlug));
        });
    }
}
