<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\Engine\MergeUndoReport;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeUndoRefusalReason;
use App\Exceptions\Engine\MergeUndoRefusedException;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RecordMerge;
use App\Support\Engine\MergeTransferCounter;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Timeline\TimelineRecorder;
use App\Traits\Engine\TranslatesUniqueViolations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class UndoRecordMergeAction
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly MergeTransferCounter $counter,
        private readonly TimelineRecorder $timeline,
        private readonly RollupOwnerStarter $rollupStarter,
    ) {}

    /**
     * @throws MergeUndoRefusedException
     * @throws Throwable
     */
    public function execute(RecordMerge $merge): MergeUndoReport
    {
        $this->guardUndoable($merge);

        $target = CustomRecord::query()
            ->withoutGlobalScopes()
            ->whereKey($merge->target_record_id)
            ->first();

        if (!$target instanceof CustomRecord) {
            throw new MergeUndoRefusedException(MergeUndoRefusalReason::TargetGone);
        }

        $source = CustomRecord::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->whereKey($merge->source_record_id)
            ->firstOrFail();

        return DB::transaction(function () use ($merge, $target, $source): MergeUndoReport {
            $this->restoreSource($source);

            [$restoredFields, $keptFields] = $this->restoreFields($merge, $target);
            [$restoredTransfers, $unrecoverable] = $this->restoreTransfers($merge, $source);

            $merge->forceFill(['undone_at' => now()])->save();

            $this->recordTimeline($target, $source, $merge);

            return new MergeUndoReport(
                (string) $merge->getKey(),
                (string) $target->getKey(),
                (string) $source->getKey(),
                $restoredFields,
                $keptFields,
                $restoredTransfers,
                $unrecoverable,
            );
        });
    }

    /**
     * @throws MergeUndoRefusedException
     */
    private function guardUndoable(RecordMerge $merge): void
    {
        if ($merge->undone_at !== null) {
            throw new MergeUndoRefusedException(MergeUndoRefusalReason::AlreadyUndone);
        }

        $windowDays = (int) config('engine.merge.undo_window_days');
        $mergedAt = $merge->created_at;

        if ($windowDays > 0 && $mergedAt !== null && $mergedAt->copy()->addDays($windowDays)->isPast()) {
            throw new MergeUndoRefusedException(MergeUndoRefusalReason::WindowExpired);
        }
    }

    /**
     * @throws MergeUndoRefusedException
     */
    private function restoreSource(CustomRecord $source): void
    {
        $source->forceFill([
            'merged_into_record_id' => null,
            'merged_at' => null,
            'deleted_at' => null,
        ]);

        try {
            $source->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new MergeUndoRefusedException(MergeUndoRefusalReason::IdentifierTaken);
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function restoreFields(RecordMerge $merge, CustomRecord $target): array
    {
        $fields = $merge->resolution['fields'] ?? [];

        if (!is_array($fields) || $fields === []) {
            return [[], []];
        }

        $data = $target->data ?? [];
        $restored = [];
        $kept = [];

        foreach ($fields as $key => $entry) {
            if (!is_array($entry) || !array_key_exists('after', $entry)) {
                continue;
            }

            $key = (string) $key;
            $after = $entry['after'];
            $before = $entry['before'] ?? null;

            if ($after === $before) {
                continue;
            }

            if (($data[$key] ?? null) !== $after) {
                $kept[] = $key;

                continue;
            }

            $data[$key] = $before;
            $restored[] = $key;
        }

        if ($restored !== []) {
            $target->forceFill(['data' => $data, 'version' => $target->version + 1])->save();
        }

        return [$restored, $kept];
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function restoreTransfers(RecordMerge $merge, CustomRecord $source): array
    {
        $transfers = $merge->transfers;
        $restored = [];
        $unrecoverable = [];

        foreach (MergeTransferCategory::cases() as $category) {
            $entry = $transfers[$category->value] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            $moved = is_array($entry['moved'] ?? null) ? $entry['moved'] : [];
            $folded = is_array($entry['folded'] ?? null) ? $entry['folded'] : [];
            $discarded = is_array($entry['discarded'] ?? null) ? $entry['discarded'] : [];

            $count = $this->rewind($category, $moved, $source);

            if ($count > 0) {
                $restored[$category->value] = $count;
            }

            $lost = count($folded) + count($discarded);

            if ($lost > 0) {
                $unrecoverable[$category->value] = $lost;
            }
        }

        return [$restored, $unrecoverable];
    }

    /**
     * @param  array<int, mixed>  $moved
     */
    private function rewind(MergeTransferCategory $category, array $moved, CustomRecord $source): int
    {
        $count = 0;

        foreach ($moved as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_array($entry['before'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $before */
            $before = $entry['before'];

            if ($category === MergeTransferCategory::Links) {
                $count += $this->rewindLink($entry['id'], $before, $source);

                continue;
            }

            $query = $this->counter->query($category, (string) $source->getKey());

            if (!$query instanceof Builder) {
                continue;
            }

            $count += $query->getModel()->newQuery()
                ->withoutGlobalScopes()
                ->whereKey($entry['id'])
                ->update($before);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function rewindLink(string $linkId, array $before, CustomRecord $source): int
    {
        $link = RecordLink::query()
            ->withoutGlobalScopes()
            ->whereKey($linkId)
            ->first();

        if (!$link instanceof RecordLink) {
            return 0;
        }

        $rewound = RecordLink::query()
            ->withoutGlobalScopes()
            ->whereKey($linkId)
            ->update($before);

        $this->rollupStarter->startForRecordIds((string) $source->getAttribute('tenant_id'), [
            (string) $link->getAttribute('from_record_id'),
            (string) ($before['from_record_id'] ?? ''),
        ]);

        return $rewound;
    }

    private function recordTimeline(CustomRecord $target, CustomRecord $source, RecordMerge $merge): void
    {
        $mergeId = (string) $merge->getKey();

        foreach ([$target, $source] as $record) {
            $this->timeline->record($record, 'merge', [[
                'source_id' => $mergeId,
                'payload' => ['direction' => 'undone'],
            ]]);
        }
    }
}
