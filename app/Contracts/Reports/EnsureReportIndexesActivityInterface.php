<?php

declare(strict_types=1);

namespace App\Contracts\Reports;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'ReportIndexes.')]
interface EnsureReportIndexesActivityInterface
{
    #[ActivityMethod(name: 'ensureReportIndex')]
    public function ensureReportIndex(string $objectTypeId, string $fieldKey): bool;
}
