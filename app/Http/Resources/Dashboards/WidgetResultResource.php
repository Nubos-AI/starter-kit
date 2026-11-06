<?php

declare(strict_types=1);

namespace App\Http\Resources\Dashboards;

use App\DTOs\Reports\ReportResultData;
use App\DTOs\Reports\WidgetResultData;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Http\Resources\Goals\GoalResource;
use App\Http\Resources\Reports\ReportResultResource;
use App\Models\Goal;
use App\Models\ObjectType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WidgetResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WidgetResultData $data */
        $data = $this->resource;

        $widget = $data->widget;

        return [
            'widget_id' => (string) $widget->getKey(),
            'title' => $widget->title,
            'chart_type' => $widget->chart_type?->value,
            'position' => $widget->position,
            'column_span' => $widget->column_span,
            'report_id' => $widget->report_id === null ? null : $widget->report_id,
            'goal_id' => $widget->goal_id === null ? null : $widget->goal_id,
            'goal' => $data->goal instanceof Goal
                ? (new GoalResource($data->goal))->resolve($request)
                : null,
            'object_type' => $data->objectType instanceof ObjectType
                ? [
                    'id' => (string) $data->objectType->getKey(),
                    'slug' => $data->objectType->slug,
                    'name' => $data->objectType->name,
                ]
                : null,
            'execution_mode' => $data->executionMode->value,
            'generated_at' => $data->generatedAt,
            'result' => $data->result instanceof ReportResultData
                ? (new ReportResultResource($data->result, $data->executionMode))->resolve($request)
                : null,
            'notice' => $data->reason instanceof ReportNotExecutableReason
                ? ['reason' => $data->reason->value, 'message' => $data->reason->message()]
                : null,
        ];
    }
}
