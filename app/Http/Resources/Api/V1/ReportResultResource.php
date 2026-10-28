<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

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
        private readonly string $reportId,
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
            'type' => 'reportResults',
            'id' => $this->reportId,
            'attributes' => [
                'aggregation' => $result->aggregation->value,
                'rows' => array_map($this->rowPayload(...), $result->rows),
                'total' => $result->total,
                'recordCount' => $result->recordCount,
                'discardedValueCount' => $result->discardedValueCount,
                'isSuppressed' => $result->isSuppressed,
                'generatedAt' => $result->generatedAt,
                'executionMode' => $this->effectiveMode->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(ReportGroupRowData $row): array
    {
        return [
            'groupValue' => $row->groupValue,
            'seriesValue' => $row->seriesValue,
            'value' => $row->value,
            'recordCount' => $row->recordCount,
            'discardedValueCount' => $row->discardedValueCount,
            'isOtherGroup' => $row->isOtherGroup,
            'isOtherSeries' => $row->isOtherSeries,
        ];
    }
}
