<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Timeline\RelationTimelineAction;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Support\Engine\RecordEndpointResolver;
use Illuminate\Database\Eloquent\Model;
use JsonException;

class RelationTimelineWriter
{
    public function __construct(
        private readonly TimelineRecorder $recorder,
        private readonly RecordEndpointResolver $endpoints,
    ) {}

    /**
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function record(RecordLink $link, RelationTimelineAction $action): void
    {
        $type = RelationshipType::query()->whereKey($link->relationship_type_id)->first();

        if (!$type instanceof RelationshipType) {
            return;
        }

        $from = $this->endpoints->find((string) $link->from_record_type, $link->from_record_id, true);
        $to = $this->endpoints->find((string) $link->to_record_type, $link->to_record_id, true);

        if (!$from instanceof Model || !$to instanceof Model) {
            return;
        }

        $this->write($link, $action, $from, $to, $type->name);
        $this->write($link, $action, $to, $from, $type->inverse_name);
    }

    /**
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    private function write(
        RecordLink $link,
        RelationTimelineAction $action,
        Model $subject,
        Model $counterpart,
        string $relationshipName,
    ): void {
        if (!$subject instanceof CustomRecord) {
            return;
        }

        $this->recorder->record($subject, 'relation', [[
            'source_id' => (string) $link->getKey(),
            'payload' => [
                'action' => $action->value,
                'relationship_type_id' => $link->relationship_type_id,
                'relationship_name' => $relationshipName,
                'counterpart_id' => (string) $counterpart->getKey(),
                'counterpart_number' => $counterpart instanceof CustomRecord ? $counterpart->record_number : null,
            ],
        ]]);
    }
}
