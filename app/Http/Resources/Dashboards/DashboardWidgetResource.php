<?php

declare(strict_types=1);

namespace App\Http\Resources\Dashboards;

use App\Models\DashboardWidget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DashboardWidget
 */
class DashboardWidgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DashboardWidget $widget */
        $widget = $this->resource;

        return [
            'id' => (string) $widget->getKey(),
            'dashboard_id' => $widget->dashboard_id,
            'report_id' => $widget->report_id === null ? null : $widget->report_id,
            'goal_id' => $widget->goal_id === null ? null : $widget->goal_id,
            'title' => $widget->title,
            'chart_type' => $widget->chart_type?->value,
            'definition' => $widget->definition,
            'position' => $widget->position,
            'column_span' => $widget->column_span,
            'updated_at' => $widget->updated_at?->toIso8601String(),
        ];
    }
}
