<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\CustomRecord;
use App\Models\RecordNote;
use JsonException;

class NoteTimelineWriter
{
    public function __construct(
        private readonly TimelineRecorder $recorder,
    ) {}

    /**
     * @param  list<array{note: RecordNote, author_name?: string|null, actor_id?: string|null, actor_type?: ActorType|null}>  $notes
     *
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function record(CustomRecord $record, array $notes): void
    {
        $entries = [];

        foreach ($notes as $item) {
            $note = $item['note'];

            $entry = [
                'source_id' => (string) $note->getKey(),
                'occurred_at' => $note->created_at,
                'payload' => [
                    'authorId' => $note->author_id,
                    'authorName' => $item['author_name'] ?? null,
                    'body' => $note->body,
                ],
            ];

            $actorId = $item['actor_id'] ?? null;
            $actorType = $item['actor_type'] ?? null;

            if ($actorId !== null || $actorType !== null) {
                $entry['actor_id'] = $actorId;
                $entry['actor_type'] = $actorType?->value;
            }

            $entries[] = $entry;
        }

        $this->recorder->record($record, 'note', $entries);
    }
}
