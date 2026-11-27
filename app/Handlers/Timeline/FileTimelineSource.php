<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Traits\Timeline\GuardsTimelineVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class FileTimelineSource implements TimelineSourceInterface
{
    use GuardsTimelineVisibility;

    public function key(): string
    {
        return 'file';
    }

    public function modelClass(): string
    {
        return Attachment::class;
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

        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($user, $record->object_type_id);

        $visible = $entries
            ->filter(static function (TimelineEntry $entry) use ($forbidden): bool {
                $payload = $entry->payload;
                $fieldKey = is_array($payload) ? $payload['fieldKey'] ?? null : null;

                return ($fieldKey === null && ($payload['recordAttachment'] ?? false) === true)
                    || (is_string($fieldKey) && !in_array($fieldKey, $forbidden, true));
            })
            ->values();

        $attachments = Attachment::query()
            ->where('record_id', $record->getKey())
            ->whereIn('id', $visible->pluck('source_id')->filter()->all())
            ->with('uploadedBy')
            ->get()
            ->keyBy('id');

        foreach ($visible as $entry) {
            $attachment = $attachments->get($entry->source_id);
            $attachment?->setRelation('record', $record);
            $entry->setAttribute('file_attachment', $attachment !== null && Gate::forUser($user)->allows('view', $attachment) ? $attachment : null);
        }

        return $visible;
    }
}
