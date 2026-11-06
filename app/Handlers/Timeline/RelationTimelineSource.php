<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Engine\RecordRelationResolver;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;

class RelationTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function __construct(private readonly RecordRelationResolver $relations) {}

    public function key(): string
    {
        return 'relation';
    }

    public function modelClass(): string
    {
        return RecordLink::class;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection
    {
        if ($this->hiddenFrom($user, $record, $entries)) {
            return $this->noEntries($entries);
        }

        $typeIds = $entries->map(static fn (TimelineEntry $entry): mixed => $entry->payload['relationship_type_id'] ?? null)
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->unique()->values()->all();
        $types = RelationshipType::query()->withTrashed()->whereKey($typeIds)->get()->keyBy('id');

        return $this->withHiddenCounterpartsRedacted($entries->filter(function (TimelineEntry $entry) use ($user, $record, $types): bool {
            $type = $types->get($entry->payload['relationship_type_id'] ?? '');

            if (!$type instanceof RelationshipType) {
                return false;
            }

            $direction = $type->from_object_type_id === $record->object_type_id
                ? RelationDirection::Outgoing
                : RelationDirection::Incoming;

            return $this->relations->mayViewCounterpart($user, $type, $direction);
        })->values());
    }
}
