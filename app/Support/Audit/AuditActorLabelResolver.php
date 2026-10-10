<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\Audit\ActorType;
use App\Models\AuditEntry;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AuditActorLabelResolver
{
    /**
     * @param  Collection<int, covariant AuditEntry|TimelineEntry>  $entries
     */
    public function stampLabels(Collection $entries): void
    {
        $labels = $this->labelsFor($entries);

        $entries->each(static function (AuditEntry|TimelineEntry $entry) use ($labels): void {
            $entry->setAttribute(
                'actor_label',
                $entry->actor_id === null || $entry->actor_type === null
                    ? null
                    : ($labels[self::keyFor($entry->actor_type, $entry->actor_id)] ?? null),
            );
        });
    }

    private static function keyFor(string $actorType, string $actorId): string
    {
        return $actorType.':'.$actorId;
    }

    /**
     * @param  Collection<int, covariant AuditEntry|TimelineEntry>  $entries
     * @return array<string, string>
     */
    private function labelsFor(Collection $entries): array
    {
        /** @var array<string, array<string, true>> $idsByType */
        $idsByType = [];

        foreach ($entries as $entry) {
            if ($entry->actor_id === null || $entry->actor_type === null) {
                continue;
            }

            $idsByType[$entry->actor_type][$entry->actor_id] = true;
        }

        $labels = [];

        foreach ($idsByType as $actorType => $actorIds) {
            foreach ($this->lookup($actorType, array_keys($actorIds)) as $actorId => $label) {
                $labels[self::keyFor($actorType, $actorId)] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param  list<string>  $actorIds
     * @return array<string, string>
     */
    private function lookup(string $actorType, array $actorIds): array
    {
        return match (ActorType::tryFrom($actorType)) {
            ActorType::User => User::query()
                ->whereKey($actorIds)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])
                ->all(),
            default => $this->moduleLabels($actorType, $actorIds),
        };
    }

    /** @param list<string> $actorIds
     * @return array<string, string>
     */
    private function moduleLabels(string $actorType, array $actorIds): array
    {
        /** @var class-string<Model>|null $model */
        $model = config('modules.audit.actor_models.'.$actorType);

        return $model === null ? [] : $model::query()->whereKey($actorIds)->pluck('name', 'id')->all();
    }
}
