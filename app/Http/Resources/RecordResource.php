<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Engine\SystemFilterField;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Engine\RecordTreeOrder;
use App\Support\Modules\RecordExtensions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomRecord
 */
class RecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomRecord $record */
        $record = $this->resource;

        return [
            'id' => $record->id,
            'objectTypeId' => $record->object_type_id,
            ...app(RecordExtensions::class)->resource($record),
            'ownerId' => $record->owner_id,
            'recordNumber' => $record->record_number,
            'title' => $this->title($record, $request->user()),
            'externalReferenceId' => $record->external_reference_id,
            'version' => $record->version,
            'data' => $this->visibleData($record, $request->user()),
            'createdAt' => $record->created_at?->toISOString(),
            'updatedAt' => $record->updated_at?->toISOString(),
            'mergedIntoRecordId' => $record->merged_into_record_id,
            'mergedAt' => $record->merged_at?->toISOString(),
            ...$this->hierarchy($record),
            ...$this->aging($record),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hierarchy(CustomRecord $record): array
    {
        $depth = $record->getAttributes()[RecordTreeOrder::$depthAttribute] ?? null;

        if (!is_numeric($depth)) {
            return [];
        }

        return ['hierarchyDepth' => (int) $depth];
    }

    /**
     * @return array<string, mixed>
     */
    private function aging(CustomRecord $record): array
    {
        $attributes = $record->getAttributes();
        $age = $attributes[SystemFilterField::AgingAge->value] ?? null;

        if (!is_numeric($age)) {
            return [];
        }

        $stage = $attributes[SystemFilterField::AgingStage->value] ?? null;
        $color = $attributes[AgingEvaluator::$colorAttribute] ?? null;
        $ruleName = $attributes[AgingEvaluator::$ruleNameAttribute] ?? null;

        return [
            'aging' => [
                'age' => (float) $age,
                'stage' => is_numeric($stage) ? (int) $stage : null,
                'color' => is_string($color) ? $color : null,
                'ruleName' => is_string($ruleName) ? $ruleName : null,
            ],
        ];
    }

    private function title(CustomRecord $record, mixed $user): string
    {
        if (!$user instanceof User) {
            return (string) ($record->record_number ?? $record->getKey());
        }

        return RecordTitleResolver::forRequest()->titleFor($user, $record);
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleData(CustomRecord $record, mixed $user): array
    {
        $data = $record->data ?? [];

        if (!$user instanceof User) {
            return $data;
        }

        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($user, $record->object_type_id);

        return array_diff_key($data, array_flip($forbidden));
    }
}
