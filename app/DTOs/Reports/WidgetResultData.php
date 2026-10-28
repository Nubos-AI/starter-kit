<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\Enums\Reports\ReportExecutionMode;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Models\DashboardWidget;
use App\Models\Goal;
use App\Models\ObjectType;

readonly class WidgetResultData
{
    public function __construct(
        public DashboardWidget $widget,
        public ReportExecutionMode $executionMode,
        public string $generatedAt,
        public ?ReportResultData $result,
        public ?ReportNotExecutableReason $reason,
        public ?ObjectType $objectType,
        public ?Goal $goal = null,
    ) {}
}
