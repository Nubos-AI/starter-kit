<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordCountData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

class RecordCountEstimator
{
    /**
     * @param  Builder<covariant Model>|QueryBuilder  $query
     */
    public function count(Builder|QueryBuilder $query): RecordCountData
    {
        $base = $query instanceof Builder ? $query->toBase() : $query;

        $probe = $base
            ->cloneWithout(['orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['order']);

        $plannedRows = $this->plannedRows($probe);

        if ($plannedRows === null || $plannedRows < $this->exactThreshold()) {
            return new RecordCountData($probe->count(), false);
        }

        return new RecordCountData($plannedRows, true);
    }

    private function plannedRows(QueryBuilder $probe): ?int
    {
        $rows = $probe->getConnection()->select(
            "explain (format json) {$probe->toSql()}",
            $probe->getBindings(),
        );

        $plan = json_decode((string) data_get($rows, '0.QUERY PLAN', ''), true);
        $plannedRows = data_get($plan, '0.Plan.Plan Rows');

        if (!is_numeric($plannedRows)) {
            Log::warning('Record count estimate unavailable, counting exactly.', [
                'table' => $probe->from,
            ]);

            return null;
        }

        return (int) $plannedRows;
    }

    private function exactThreshold(): int
    {
        return (int) config('engine.counts.exact_threshold');
    }
}
