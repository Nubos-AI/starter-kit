<?php

declare(strict_types=1);

namespace App\Handlers\Timeline;

use App\Contracts\Timeline\TimelineSourceInterface;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Support\Collection;

class ChangeTimelineSource implements TimelineSourceInterface
{
    public function key(): string
    {
        return 'field_change';
    }

    public function modelClass(): string
    {
        return AuditEntry::class;
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    public function filterVisible(User $user, CustomRecord $record, Collection $entries): Collection
    {
        $ability = "{$record->objectType->slug}.".ObjectTypeAbility::AuditView->value;

        if (!$user->hasPermission($ability)) {
            return $entries->take(0);
        }

        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($user, $record->object_type_id);

        $visible = $entries
            ->filter(static function (TimelineEntry $entry) use ($forbidden): bool {
                $payload = $entry->payload;
                $fieldKey = is_array($payload) ? $payload['field_key'] ?? null : null;

                return is_string($fieldKey) && !in_array($fieldKey, $forbidden, true);
            })
            ->values();

        return $this->withReferenceLabels($user, $record, $visible);
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    private function withReferenceLabels(User $user, CustomRecord $record, Collection $entries): Collection
    {
        $ownerIds = $entries
            ->filter(static fn (TimelineEntry $entry): bool => ($entry->payload['field_key'] ?? null) === 'owner_id')
            ->flatMap(static fn (TimelineEntry $entry): array => [$entry->payload['old_value'] ?? null, $entry->payload['new_value'] ?? null])
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()->values()->all();

        if ($ownerIds === [] && $this->additionalLabels($user, $record, $entries) === []) {
            return $entries;
        }

        $owners = User::query()->withTrashed()
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($ownerIds)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(static fn (User $owner): array => [$owner->id => $owner->name])
            ->all();

        $labels = [
            'owner_id' => $owners,
            ...$this->additionalLabels($user, $record, $entries),
        ];

        return $entries->map(static function (TimelineEntry $entry) use ($labels): TimelineEntry {
            $payload = $entry->payload;
            $fieldKey = $payload['field_key'] ?? null;

            if (!is_string($fieldKey) || !array_key_exists($fieldKey, $labels)) {
                return $entry;
            }

            foreach (['old', 'new'] as $side) {
                $id = $payload[$side.'_value'] ?? null;
                $payload[$side.'_label'] = is_string($id) && $id !== ''
                    ? ($labels[$fieldKey][$id] ?? __('i18n.backend.handlers.timeline.change_timeline_source.unavailable'))
                    : null;
            }

            $labelled = clone $entry;
            $labelled->payload = $payload;

            return $labelled;
        });
    }

    /** @param Collection<int, TimelineEntry> $entries
     * @return array<string, array<string, string>>
     */
    protected function additionalLabels(User $user, CustomRecord $record, Collection $entries): array
    {
        return [];
    }
}
