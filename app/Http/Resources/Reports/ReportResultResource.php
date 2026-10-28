<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\DTOs\Reports\ReportGroupRowData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\ReportExecutionMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResultResource extends JsonResource
{
    public function __construct(
        ReportResultData $resource,
        private readonly ReportExecutionMode $effectiveMode,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReportResultData $result */
        $result = $this->resource;

        return [
            'aggregation' => $result->aggregation->value,
            'rows' => array_map($this->rowPayload(...), $result->rows),
            'total' => $result->total,
            'record_count' => $result->recordCount,
            'discarded_value_count' => $result->discardedValueCount,
            'is_suppressed' => $result->isSuppressed,
            'generated_at' => $result->generatedAt,
            'execution_mode' => $this->effectiveMode->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(ReportGroupRowData $row): array
    {
        return [
            'group_value' => $row->groupValue,
            'series_value' => $row->seriesValue,
            'value' => $row->value,
            'record_count' => $row->recordCount,
            'discarded_value_count' => $row->discardedValueCount,
            'is_other_group' => $row->isOtherGroup,
            'is_other_series' => $row->isOtherSeries,
        ];
    }
}
