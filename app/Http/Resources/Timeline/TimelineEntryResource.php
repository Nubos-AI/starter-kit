<?php

declare(strict_types=1);

namespace App\Http\Resources\Timeline;

use App\Http\Resources\Engine\AttachmentResource;
use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Audit\AuditActorLabelResolver;
use App\Support\Timeline\TimelineEntryAccessResolver;
use App\Support\Timeline\TimelineSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin TimelineEntry
 */
class TimelineEntryResource extends JsonResource
{
    /**
     * @param  Collection<int, TimelineEntry>  $entries
     */
    public static function redactedCollection(
        User $user,
        CustomRecord $record,
        Collection $entries,
        TimelineSourceRegistry $registry,
    ): AnonymousResourceCollection {
        /** @var array<string, TimelineEntry> $survivors */
        $survivors = [];

        foreach ($entries->groupBy('source_key') as $sourceKey => $group) {
            if (!$registry->hasSource((string) $sourceKey)) {
                continue;
            }

            foreach ($registry->sourceFor((string) $sourceKey)->filterVisible($user, $record, $group) as $visibleEntry) {
                $survivors[$visibleEntry->id] = $visibleEntry;
            }
        }

        $visible = $entries
            ->filter(static fn (TimelineEntry $entry): bool => isset($survivors[$entry->id]))
            ->map(static fn (TimelineEntry $entry): TimelineEntry => $survivors[$entry->id])
            ->values();

        (new AuditActorLabelResolver)->stampLabels($visible);
        app(TimelineEntryAccessResolver::class)->stampAccess($user, $visible);

        return self::collection($visible);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TimelineEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'sourceKey' => $entry->source_key,
            'sourceId' => $entry->source_id,
            'actorId' => $entry->actor_id,
            'actorType' => $entry->actor_type,
            'actorLabel' => $entry->getAttribute('actor_label'),
            'channel' => $entry->channel,
            'occurredAt' => $entry->occurred_at->toISOString(),
            'payload' => $entry->payload,
            'canOpenAutomation' => $entry->getAttribute('can_open_automation') === true,
            'file' => $this->when($entry->source_key === 'file', function () use ($entry, $request): ?array {
                $attachment = $entry->getAttribute('file_attachment');

                return $attachment instanceof Attachment ? AttachmentResource::make($attachment)->resolve($request) : null;
            }),
        ];
    }
}
