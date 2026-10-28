<?php

declare(strict_types=1);

namespace Tests\Unit\Goals\Doubles;

use App\Enums\Reports\ReportExecutionMode;
use App\Models\Report;
use App\Support\Reports\ReportExecutionModeResolver;

class StaticReportExecutionModeResolver extends ReportExecutionModeResolver
{
    public function __construct(private readonly ReportExecutionMode $mode = ReportExecutionMode::Viewer) {}

    public function effectiveMode(?Report $report): ReportExecutionMode
    {
        return $this->mode;
    }
}
