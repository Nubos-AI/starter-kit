<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\Attachment;
use App\Models\CustomRecord;
use JsonException;

class AttachmentTimelineWriter
{
    public function __construct(
        private readonly TimelineRecorder $recorder,
    ) {}

    /**
     * @param  list<array{attachment: Attachment, template_name?: string|null, actor_id?: string|null, actor_type?: ActorType|null}>  $attachments
     *
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function record(CustomRecord $record, array $attachments): void
    {
        $entries = [];

        foreach ($attachments as $item) {
            $attachment = $item['attachment'];
            $source = $this->sourceKey($attachment);
            $templateName = $item['template_name'] ?? null;

            $payload = [
                'fileName' => $attachment->original_name,
                'size' => $attachment->size,
                'mimeType' => $attachment->mime,
            ];

            if ($source === 'file') {
                $payload['fieldKey'] = $attachment->field_key;
                if ($attachment->field_key === null) {
                    $payload['recordAttachment'] = true;
                }
            } elseif ($templateName !== null) {
                $payload['templateName'] = $templateName;
            }

            $entry = [
                'source_id' => (string) $attachment->getKey(),
                'occurred_at' => $attachment->created_at,
                'payload' => $payload,
            ];

            $actorId = $item['actor_id'] ?? null;
            $actorType = $item['actor_type'] ?? null;

            if ($actorId !== null || $actorType !== null) {
                $entry['actor_id'] = $actorId;
                $entry['actor_type'] = $actorType?->value;
            }

            $entries[$source][] = $entry;
        }

        foreach ($entries as $source => $items) {
            $this->recorder->record($record, $source, $items);
        }
    }

    public function sourceKey(Attachment $attachment): string
    {
        if ($attachment->field_key === null) {
            return 'file';
        }

        return (string) config('modules.attachments.timeline_sources.'.$attachment->field_key, 'file');
    }
}
