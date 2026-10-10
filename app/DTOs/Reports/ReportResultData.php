<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\Enums\Reports\AggregationType;

readonly class ReportResultData
{
    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    public function __construct(
        public AggregationType $aggregation,
        public array $rows,
        public ?string $total,
        public int $recordCount,
        public int $discardedValueCount,
        public bool $isSuppressed,
        public string $generatedAt,
    ) {}
}
