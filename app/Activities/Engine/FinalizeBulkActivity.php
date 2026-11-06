<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\FinalizeBulkActivityInterface;
use App\Support\Engine\BulkFinalizer;

class FinalizeBulkActivity implements FinalizeBulkActivityInterface
{
    public function __construct(private readonly BulkFinalizer $finalizer) {}

    /**
     * @param  list<string>  $affectedIds
     */
    public function finalizeBulk(
        string $tenantId,
        string $actingUserId,
        string $reportKey,
        array $affectedIds,
        int $threshold,
        string $objectTypeSlug,
        bool $notify,
    ): void {
        $this->finalizer->finalize($tenantId, $actingUserId, $reportKey, $affectedIds, $threshold, $objectTypeSlug, $notify);
    }
}
