<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Enums\Reports\ReportActionRefusalReason;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
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

        $user = $request->user();

        $canView = $user instanceof User && $user->can('view', $report);
        $canUpdate = $canView && $user->can('update', $report);
        $canDelete = $canView && $user->can('delete', $report);

        return [
            'id' => (string) $report->getKey(),
            'name' => $report->name,
            'description' => $report->description,
            'object_type_id' => $report->object_type_id,
            'object_type' => $this->whenLoaded('objectType', fn (): ?array => $this->objectTypePayload($report)),
            'filter_definition' => $this->visibleFilterDefinition($report, $user),
            'aggregation_type' => $report->aggregation_type->value,
            'aggregation_field_key' => $report->aggregation_field_key,
            'group_by_field_key' => $report->group_by_field_key,
            'group_by_bucket' => $report->group_by_bucket?->value,
            'series_field_key' => $report->series_field_key,
            'chart_type' => $report->chart_type->value,
            'execution_mode' => $report->execution_mode->value,
            'is_owner' => $user instanceof User && $report->owner_id === $user->getKey(),
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'update_reason' => $this->refusalReason($canUpdate, $canView)?->value,
            'delete_reason' => $this->refusalReason($canDelete, $canView)?->value,
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    private function refusalReason(bool $isAllowed, bool $canView): ?ReportActionRefusalReason
    {
        if ($isAllowed) {
            return null;
        }

        return $canView
            ? ReportActionRefusalReason::NotOwner
            : ReportActionRefusalReason::ObjectTypeNotPermitted;
    }

    /**
     * @return array{id: string, slug: string, name: string}|null
     */
    private function objectTypePayload(Report $report): ?array
    {
        $objectType = $report->objectType;

        if (!$objectType instanceof ObjectType) {
            return null;
        }

        return [
            'id' => (string) $objectType->getKey(),
            'slug' => $objectType->slug,
            'name' => $objectType->name,
        ];
    }
}
