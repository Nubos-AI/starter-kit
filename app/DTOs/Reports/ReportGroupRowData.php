<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

readonly class ReportGroupRowData
{
    public function __construct(
        public ?string $groupValue,
        public ?string $seriesValue,
        public ?string $value,
        public int $recordCount,
        public ?string $valueSum = null,
        public ?int $valueCount = null,
        public int $discardedValueCount = 0,
        public bool $isOtherGroup = false,
        public bool $isOtherSeries = false,
    ) {}
}
