<?php

declare(strict_types=1);

namespace App\Contracts\Export;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Export.')]
interface FinalizeExportActivityInterface
{
    #[ActivityMethod(name: 'finalizeExport')]
    public function finalizeExport(string $tenantId, string $userId, string $exportJobId): bool;

    #[ActivityMethod(name: 'notifyExportCompleted')]
    public function notifyExportCompleted(string $tenantId, string $userId, string $exportJobId): void;
}
