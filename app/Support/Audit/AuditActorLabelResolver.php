<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\Audit\ActorType;
use App\Models\AuditEntry;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class AuditActorLabelResolver
{
    /**
     * @param  Collection<int, covariant AuditEntry|TimelineEntry>  $entries
     */
    public function stampLabels(Collection $entries): void
    {
        $labels = $this->labelsFor($entries);
        $tokenLabels = $this->apiTokenLabelsFor($entries);

        $entries->each(static function (AuditEntry|TimelineEntry $entry) use ($labels, $tokenLabels): void {
            $entry->setAttribute(
                'actor_label',
                $entry->actor_id === null || $entry->actor_type === null
                    ? null
                    : ($labels[self::keyFor($entry->actor_type, $entry->actor_id)] ?? null),
            );

            $entry->setAttribute(
                'api_token_label',
                $entry->api_token_id === null ? null : ($tokenLabels[$entry->api_token_id] ?? null),
            );
        });
    }

    /**
     * @param  Collection<int, covariant AuditEntry|TimelineEntry>  $entries
     * @return array<int, string>
     */
    private function apiTokenLabelsFor(Collection $entries): array
    {
        $tokenIds = $entries
            ->map(static fn (AuditEntry|TimelineEntry $entry): ?int => $entry->api_token_id)
            ->filter(static fn (?int $tokenId): bool => $tokenId !== null)
            ->unique()
            ->values()
            ->all();

        if ($tokenIds === []) {
            return [];
        }

        return PersonalAccessToken::query()
            ->whereKey($tokenIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, int|string $id): array => [(int) $id => (string) $name])
            ->all();
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
