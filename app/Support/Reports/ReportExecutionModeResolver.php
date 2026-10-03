<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\Reports\ReportExecutionMode;
use App\Models\Report;
use App\Models\User;

class ReportExecutionModeResolver
{
    public function __construct(private readonly ReportDefinerSource $definers) {}

    public function effectiveMode(?Report $report): ReportExecutionMode
    {
        if (!$report instanceof Report || $report->execution_mode !== ReportExecutionMode::Definer) {
            return ReportExecutionMode::Viewer;
        }

        $definer = $this->definers->find($report->owner_id);

        return $definer instanceof User && $definer->isEscalatedAuthority()
            ? ReportExecutionMode::Definer
            : ReportExecutionMode::Viewer;
    }
}
