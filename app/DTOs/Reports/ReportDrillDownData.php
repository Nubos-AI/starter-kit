<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

readonly class ReportDrillDownData
{
    public function __construct(
        public ?string $reportId,
        public ?string $dashboardId,
        public ?string $widgetId,
        public string $groupToken,
        public ?string $seriesToken,
    ) {}
}
