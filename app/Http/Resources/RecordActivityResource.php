<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RecordActivity;
use App\Traits\Links\CarriesSourceLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecordActivity */
class RecordActivityResource extends JsonResource
{
    use CarriesSourceLinks;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recordId' => $this->record_id,
            'sourceType' => $this->source_type,
            'sourceId' => $this->source_id,
            'subject' => $this->subject,
            'occurredAt' => $this->occurred_at->toISOString(),
            'result' => $this->result,
            'activityTypeId' => $this->activity_type_id,
            'assigneeId' => $this->assignee_id,
            'typeName' => $this->whenLoaded('activityType', fn () => $this->activityType?->name),
            'assigneeName' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'links' => $this->sourceLinks,
        ];
    }
}
