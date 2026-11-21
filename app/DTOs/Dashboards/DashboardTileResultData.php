<?php

declare(strict_types=1);

namespace App\DTOs\Dashboards;

use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\GroupingBucket;

readonly class DashboardTileResultData
{
    public function __construct(
        public ReportResultData $result,
        public ?string $currency = null,
        public ?GroupingBucket $bucket = null,
    ) {}
}
