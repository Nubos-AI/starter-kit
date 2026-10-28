<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Engine\SystemFilterField;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Engine\RecordPresenter;
use App\Support\Engine\RecordTreeOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomRecord
 */
class RecordResource extends JsonResource
{
    private readonly RecordPresenter $presenter;

    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        $this->presenter = app(RecordPresenter::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomRecord $record */
        $record = $this->resource;
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;

        return [
            'id' => $record->id,
            'objectTypeId' => $record->object_type_id,
            ...$this->presenter->extensions($record),
            'ownerId' => $record->owner_id,
            'recordNumber' => $record->record_number,
            'title' => $this->presenter->title($record, $viewer),
            'externalReferenceId' => $record->external_reference_id,
            'version' => $record->version,
            'data' => $this->presenter->visibleData($record, $viewer),
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
}
