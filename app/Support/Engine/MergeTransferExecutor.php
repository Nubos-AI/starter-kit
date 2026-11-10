<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RecordWatcher;
use Illuminate\Database\Eloquent\Builder;

class MergeTransferExecutor
{
    /**
     * @var array<string, string>
     */
    private array $simpleCategories = [
        MergeTransferCategory::Attachments->value => 'record_id',
        MergeTransferCategory::Notes->value => 'record_id',
        MergeTransferCategory::Reminders->value => 'record_id',
    ];

    public function __construct(
        private readonly MergeTransferCounter $counter,
        private readonly RollupOwnerStarter $rollupStarter,
    ) {}

    /**
     * @return array<string, array{policy: string, moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}>
     */
    public function execute(CustomRecord $target, CustomRecord $source, MergeRuleDecision $decision): array
    {
        $report = [];

        foreach (MergeTransferCategory::cases() as $category) {
            $policy = $decision->policyFor($category);

            $report[$category->value] = [
                'policy' => $policy->value,
                ...$this->applyCategory($category, $policy, $target, $source),
            ];
        }

        return $report;
    }

    /**
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function applyCategory(
        MergeTransferCategory $category,
        MergeTransferPolicy $policy,
        CustomRecord $target,
        CustomRecord $source,
    ): array {
        if ($policy === MergeTransferPolicy::Keep) {
            return $this->report();
        }

        if ($policy === MergeTransferPolicy::Discard) {
            return $this->discard($category, $source);
        }

        return match ($category) {
            MergeTransferCategory::Links => $this->moveLinks($target, $source),
            MergeTransferCategory::Watchers => $this->moveWatchers($target, $source),
            MergeTransferCategory::Attachments,
            MergeTransferCategory::Notes,
            MergeTransferCategory::Reminders => $this->moveSimple($category, $target, $source),
            default => $this->report(),
        };
    }

    /**
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function discard(MergeTransferCategory $category, CustomRecord $source): array
    {
        if ($category !== MergeTransferCategory::AgingStates) {
            return $this->report();
        }

        $query = $this->counter->query($category, (string) $source->getKey());

        if ($query === null) {
            return $this->report();
        }

        $ids = $query
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($ids !== []) {
            $query->whereKey($ids)->delete();
        }

        return $this->report(discarded: array_values($ids));
    }

    /**
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function moveSimple(MergeTransferCategory $category, CustomRecord $target, CustomRecord $source): array
    {
        $query = $this->counter->query($category, (string) $source->getKey());

        if (!$query instanceof Builder) {
            return $this->report();
        }

        $column = $this->simpleCategories[$category->value];

        $moved = [];

        foreach ($query->pluck('id') as $id) {
            $moved[] = [
                'id' => (string) $id,
                'before' => [$column => (string) $source->getKey()],
            ];
        }

        if ($moved === []) {
            return $this->report();
        }

        $fresh = $this->counter->query($category, (string) $source->getKey());

        if ($fresh instanceof Builder) {
            $fresh->update([$column => $target->getKey()]);
        }

        return $this->report(moved: $moved);
    }

    /**
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function moveWatchers(CustomRecord $target, CustomRecord $source): array
    {
        $moved = [];
        $folded = [];

        $watchers = RecordWatcher::query()
            ->withoutGlobalScopes()
            ->where('record_id', $source->getKey())
            ->get();

        foreach ($watchers as $watcher) {
            $taken = RecordWatcher::query()
                ->withoutGlobalScopes()
                ->where('record_id', $target->getKey())
                ->where('user_id', $watcher->user_id)
                ->exists();

            if ($taken) {
                $folded[] = (string) $watcher->getKey();
                $watcher->delete();

                continue;
            }

            $moved[] = [
                'id' => (string) $watcher->getKey(),
                'before' => ['record_id' => (string) $source->getKey()],
            ];
            $watcher->forceFill(['record_id' => $target->getKey()])->save();
        }

        return $this->report(moved: $moved, folded: $folded);
    }

    /**
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function moveLinks(CustomRecord $target, CustomRecord $source): array
    {
        $moved = [];
        $folded = [];

        $targetId = (string) $target->getKey();
        $sourceId = (string) $source->getKey();

        $links = RecordLink::query()
            ->withoutGlobalScopes()
            ->where(function (Builder $query) use ($sourceId): void {
                $query->where('from_record_id', $sourceId)->orWhere('to_record_id', $sourceId);
            })
            ->get();

        /** @var list<string> $owners */
        $owners = [];

        foreach ($links as $link) {
            $from = $link->from_record_id === $sourceId ? $targetId : $link->from_record_id;
            $to = $link->to_record_id === $sourceId ? $targetId : $link->to_record_id;

            $owners[] = $link->from_record_id;
            $owners[] = $from;

            if ($from === $to || $this->edgeExists($link->relationship_type_id, $from, $to, (string) $link->getKey())) {
                $folded[] = (string) $link->getKey();
                $link->delete();

                continue;
            }

            $moved[] = [
                'id' => (string) $link->getKey(),
                'before' => [
                    'from_record_id' => $link->from_record_id,
                    'to_record_id' => $link->to_record_id,
                ],
            ];
            $link->forceFill(['from_record_id' => $from, 'to_record_id' => $to])->save();
        }

        $this->rollupStarter->startForRecordIds((string) $target->getAttribute('tenant_id'), $owners);

        return $this->report(moved: $moved, folded: $folded);
    }

    private function edgeExists(string $relationshipTypeId, string $from, string $to, string $exceptId): bool
    {
        return RecordLink::query()
            ->withoutGlobalScopes()
            ->where('relationship_type_id', $relationshipTypeId)
            ->where('from_record_id', $from)
            ->where('to_record_id', $to)
            ->whereKeyNot($exceptId)
            ->exists();
    }

    /**
     * @param  list<array{id: string, before: array<string, string>}>  $moved
     * @param  list<string>  $folded
     * @param  list<string>  $discarded
     * @return array{moved: list<array{id: string, before: array<string, string>}>, folded: list<string>, discarded: list<string>}
     */
    private function report(array $moved = [], array $folded = [], array $discarded = []): array
    {
        return ['moved' => $moved, 'folded' => $folded, 'discarded' => $discarded];
    }
}
