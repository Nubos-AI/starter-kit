<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;

class MarkReportAsSystemAction
{
    public function execute(Report $report): void
    {
        if ($report->is_system) {
            return;
        }

        Report::query()
            ->whereKey($report->getKey())
            ->update(['is_system' => true]);

        $report->is_system = true;
    }
}
