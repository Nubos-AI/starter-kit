<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Bulk.')]
interface FinalizeBulkActivityInterface
{
    /**
     * @param  list<string>  $affectedIds
     */
    #[ActivityMethod(name: 'finalizeBulk')]
    public function finalizeBulk(
        string $tenantId,
        string $actingUserId,
        string $reportKey,
        array $affectedIds,
        int $threshold,
        string $objectTypeSlug,
        bool $notify,
    ): void;
}
