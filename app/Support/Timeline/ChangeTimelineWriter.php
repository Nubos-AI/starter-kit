<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\CustomRecord;
use DateTimeInterface;
use JsonException;

class ChangeTimelineWriter
{
    public function __construct(
        protected readonly TimelineRecorder $recorder,
    ) {}

    /**
     * @param  list<array{source_id: string, occurred_at: DateTimeInterface, field_key: string, old: mixed, new: mixed, actor_id: string|null, actor_type: string|null}>  $changes
     * @param  array<int, string>  $encryptedFieldKeys
     *
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function record(CustomRecord $record, array $changes, array $encryptedFieldKeys): void
    {
        $changeEntries = [];

        foreach ($changes as $change) {
            $automation = $change['actor_type'] === ActorType::Automation->value && $change['actor_id'] !== null
                ? ['automation_id' => $change['actor_id']]
                : [];

            if (in_array($change['field_key'], [...$encryptedFieldKeys, ...config('modules.timeline.reserved_fields', [])], true)) {
                continue;
            }

            $changeEntries[] = [
                'source_id' => $change['source_id'],
                'occurred_at' => $change['occurred_at'],
                'actor_id' => $change['actor_id'],
                'actor_type' => $change['actor_type'],
                'payload' => array_merge([
                    'field_key' => $change['field_key'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                ], $automation),
            ];
        }

        $this->recorder->record($record, 'field_change', $changeEntries);
    }
}
