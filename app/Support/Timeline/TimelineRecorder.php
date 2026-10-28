<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use App\Enums\Audit\ActorType;
use App\Enums\Timeline\TimelineChannel;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Support\Audit\ActorResolver;
use DateTimeInterface;
use Illuminate\Support\Str;
use JsonException;

class TimelineRecorder
{
    public function __construct(
        private readonly TimelineSourceRegistry $sources,
        private readonly ActorResolver $actors,
    ) {}

    /**
     * @param  list<array{source_id?: string|null, occurred_at?: DateTimeInterface|null, payload?: array<string, mixed>|null, actor_id?: string|null, actor_type?: string|null}>  $entries
     *
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function record(CustomRecord $record, string $sourceKey, array $entries): void
    {
        if (!$this->sources->hasSource($sourceKey)) {
            throw new UnknownTimelineSourceException($sourceKey);
        }

        if ($entries === []) {
            return;
        }

        $tenantId = $record->tenant_id;
        $recordId = (string) $record->getKey();
        [$actorId, $actorType] = $this->actors->resolve();
        $now = now();

        $rows = [];

        foreach ($entries as $entry) {
            $payload = $entry['payload'] ?? null;
            $carriesActor = array_key_exists('actor_id', $entry) && array_key_exists('actor_type', $entry);

            $effectiveActorType = $carriesActor ? $entry['actor_type'] : $actorType;

            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'record_id' => $recordId,
                'source_key' => $sourceKey,
                'source_id' => $entry['source_id'] ?? null,
                'actor_id' => $carriesActor ? $entry['actor_id'] : $actorId,
                'actor_type' => $effectiveActorType,
                'channel' => $this->channelFor($effectiveActorType)->value,
                'occurred_at' => $entry['occurred_at'] ?? $now,
                'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TimelineEntry::query()->insert($rows);
    }

    private function channelFor(?string $actorType): TimelineChannel
    {
        if ($actorType === ActorType::Automation->value) {
            return TimelineChannel::Automation;
        }

        if ($actorType !== ActorType::User->value) {
            return TimelineChannel::System;
        }

        return request()->is('api/*') ? TimelineChannel::Api : TimelineChannel::Web;
    }
}
