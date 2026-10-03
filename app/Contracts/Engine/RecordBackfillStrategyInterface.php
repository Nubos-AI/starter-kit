<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\Enums\Engine\RecordBackfillKind;
use App\Models\CustomRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface RecordBackfillStrategyInterface
{
    public function kind(): RecordBackfillKind;

    public function batchSize(): int;

    /**
     * @param  Builder<CustomRecord>  $query
     */
    public function constrainQuery(Builder $query): void;

    /**
     * @param  Collection<int, CustomRecord>  $records
     */
    public function applyBatch(string $tenantId, Collection $records): int;
}
