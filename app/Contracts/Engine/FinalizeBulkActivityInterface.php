<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Bulk.')]
interface FinalizeBulkActivityInterface
{
    /**
     * @param  list<list<string>>  $failedChunks
     */
    #[ActivityMethod(name: 'finalizeBulk')]
    public function finalizeBulk(string $reportKey, array $failedChunks = []): void;

    /**
     * @param  list<string>  $affectedIds
     */
    #[ActivityMethod(name: 'notifyBulkRecipients')]
    public function notifyBulkRecipients(string $tenantId, string $actingUserId, array $affectedIds, string $objectTypeSlug): void;
}
