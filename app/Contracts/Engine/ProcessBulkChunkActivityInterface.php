<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Bulk.')]
interface ProcessBulkChunkActivityInterface
{
    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>  $payload
     */
    #[ActivityMethod(name: 'processBulkChunk')]
    public function processBulkChunk(
        string $action,
        string $tenantId,
        string $actingUserId,
        string $objectTypeId,
        array $recordIds,
        array $payload,
        string $reportKey,
    ): void;
}
