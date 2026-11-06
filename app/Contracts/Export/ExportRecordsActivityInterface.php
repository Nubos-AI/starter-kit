<?php

declare(strict_types=1);

namespace App\Contracts\Export;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Export.')]
interface ExportRecordsActivityInterface
{
    #[ActivityMethod(name: 'exportRecords')]
    public function exportRecords(string $tenantId, string $actingUserId, string $exportJobId): void;
}
