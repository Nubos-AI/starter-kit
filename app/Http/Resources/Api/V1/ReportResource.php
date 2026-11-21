<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Report;
use App\Traits\Reports\RedactsForbiddenFilterConditions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    use RedactsForbiddenFilterConditions;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Report $report */
        $report = $this->resource;

        $key = (string) $report->getKey();

        return [
            'type' => 'reports',
            'id' => $key,
            'attributes' => $this->attributePayload($report, $request),
            'relationships' => [
                'objectType' => [
                    'data' => [
                        'type' => 'objectTypes',
                        'id' => $report->object_type_id,
                    ],
                ],
            ],
            'links' => [
                'self' => url("/api/v1/reports/{$key}"),
                'result' => url("/api/v1/reports/{$key}/result"),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributePayload(Report $report, Request $request): array
    {
        return [
            'name' => $report->name,
            'description' => $report->description,
            'objectTypeSlug' => $report->objectType?->slug,
            'filterDefinition' => (object) $this->visibleFilterDefinition($report, $request->user()),
            'aggregationType' => $report->aggregation_type->value,
            'aggregationFieldKey' => $report->aggregation_field_key,
            'groupByFieldKey' => $report->group_by_field_key,
            'groupByBucket' => $report->group_by_bucket?->value,
            'seriesFieldKey' => $report->series_field_key,
            'chartType' => $report->chart_type->value,
            'executionMode' => $report->execution_mode->value,
            'createdAt' => $report->created_at?->toISOString(),
            'updatedAt' => $report->updated_at?->toISOString(),
        ];
    }
}
