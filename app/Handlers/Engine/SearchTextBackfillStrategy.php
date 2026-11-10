<?php

declare(strict_types=1);

namespace App\Handlers\Engine;

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\Enums\Engine\RecordBackfillKind;
use App\Models\CustomRecord;
use App\Support\Engine\RecordSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SearchTextBackfillStrategy implements RecordBackfillStrategyInterface
{
    public function __construct(private readonly RecordSearchText $searchText) {}

    public function kind(): RecordBackfillKind
    {
        return RecordBackfillKind::SearchText;
    }

    public function batchSize(): int
    {
        return max(1, (int) config('engine.backfill.search_text_batch_size'));
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    public function constrainQuery(Builder $query): void {}

    /**
     * @param  Collection<int, CustomRecord>  $records
     */
    public function applyBatch(string $tenantId, Collection $records): int
    {
        $first = $records->first();

        if (!$first instanceof CustomRecord) {
            return 0;
        }

        $this->searchText->refreshMany(
            $tenantId,
            $first->object_type_id,
            array_values($records->map(static fn (CustomRecord $record): string => (string) $record->getKey())->all()),
        );

        return 0;
    }
}
