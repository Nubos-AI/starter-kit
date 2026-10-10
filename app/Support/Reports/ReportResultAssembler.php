<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportGroupRowData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\AggregationType;
use Closure;

class ReportResultAssembler
{
    public function __construct(
        private readonly ?int $kThreshold = null,
        private readonly ?int $maxCategories = null,
    ) {}

    /**
     * @param  list<ReportGroupRowData>  $categoryRows
     * @param  list<ReportGroupRowData>  $cellRows
     * @param  (Closure(list<list<ReportGroupRowData>>): list<?string>)|null  $foldedValueResolver
     */
    public function assemble(
        ReportDefinitionData $definition,
        array $categoryRows,
        array $cellRows,
        string $generatedAt,
        ?string $ungroupedValue = null,
        ?Closure $foldedValueResolver = null,
    ): ReportResultData {
        $threshold = $this->kThreshold ?? (int) config('reports.k_anonymity_threshold');
        $cap = $this->maxCategories ?? (int) config('reports.max_categories');

        if ($this->recordSum($cellRows) === 0) {
            return new ReportResultData(
                $definition->aggregation,
                [],
                $this->neutralTotal($definition->aggregation, $ungroupedValue),
                0,
                0,
                false,
                $generatedAt,
            );
        }

        $cells = $this->cellsByCategory($cellRows);

        $eligible = [];
        $folded = [];

        foreach ($categoryRows as $categoryRow) {
            $categoryCells = $cells[$this->categoryKey($categoryRow->groupValue)] ?? [];

            if ($categoryRow->recordCount < $threshold) {
                $folded = [...$folded, ...$categoryCells];

                continue;
            }

            $eligible[] = [$categoryRow, $categoryCells];
        }

        usort($eligible, fn (array $left, array $right): int => $this->rankCategories($left[0], $right[0]));

        foreach (array_slice($eligible, max($cap, 0)) as $overflow) {
            $folded = [...$folded, ...$overflow[1]];
        }

        $eligible = array_slice($eligible, 0, max($cap, 0));

        while ($folded !== [] && $eligible !== [] && $this->recordSum($folded) < $threshold) {
            $absorbed = array_pop($eligible);
            $folded = [...$folded, ...$absorbed[1]];
        }

        if ($folded !== [] && $this->recordSum($folded) < $threshold) {
            return new ReportResultData($definition->aggregation, [], null, 0, 0, true, $generatedAt);
        }

        $plan = [];

        foreach ($eligible as [$categoryRow, $categoryCells]) {
            [$visible, $thin] = $this->splitCells($categoryCells, $threshold);

            $plan[] = [$categoryRow->groupValue, $visible, $thin];
        }

        $pending = [];

        foreach ($plan as [, , $thin]) {
            if ($thin !== []) {
                $pending[] = $thin;
            }
        }

        if ($folded !== []) {
            $pending[] = $folded;
        }

        $resolved = $this->isDecomposable($definition->aggregation) || $pending === [] || !$foldedValueResolver instanceof Closure
            ? []
            : $foldedValueResolver($pending);

        $rows = [];
        $cursor = 0;

        foreach ($plan as [$groupValue, $visible, $thin]) {
            foreach ($visible as $cell) {
                $rows[] = $cell;
            }

            if ($thin === []) {
                continue;
            }

            $rows[] = $this->collapse($definition->aggregation, $thin, $groupValue, $resolved[$cursor] ?? null, false);
            $cursor++;
        }

        if ($folded !== []) {
            $rows[] = $this->collapse($definition->aggregation, $folded, null, $resolved[$cursor] ?? null, true);
        }

        return new ReportResultData(
            $definition->aggregation,
            $rows,
            $this->combine($definition->aggregation, $rows, $ungroupedValue),
            $this->recordSum($rows),
            $this->discardedSum($rows),
            false,
            $generatedAt,
        );
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function collapse(
        AggregationType $aggregation,
        array $rows,
        ?string $groupValue,
        ?string $resolvedValue,
        bool $isOtherGroup,
    ): ReportGroupRowData {
        $isAverage = $aggregation === AggregationType::Avg;

        return new ReportGroupRowData(
            $isOtherGroup ? null : $groupValue,
            null,
            $this->combine($aggregation, $rows, $resolvedValue),
            $this->recordSum($rows),
            $isAverage ? $this->trimDecimal($this->valueSumOf($rows)) : null,
            $isAverage ? $this->valueCountOf($rows) : null,
            $this->discardedSum($rows),
            $isOtherGroup,
            !$isOtherGroup,
        );
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function combine(AggregationType $aggregation, array $rows, ?string $resolvedValue): ?string
    {
        return match ($aggregation) {
            AggregationType::Count => (string) $this->recordSum($rows),
            AggregationType::Sum => $this->sumOfValues($rows),
            AggregationType::Avg => $this->meanOfValues($rows),
            AggregationType::Min => $this->extremeValue($rows, true),
            AggregationType::Max => $this->extremeValue($rows, false),
            AggregationType::DistinctCount => $resolvedValue,
        };
    }

    /**
     * @param  list<ReportGroupRowData>  $cells
     * @return array{list<ReportGroupRowData>, list<ReportGroupRowData>}
     */
    private function splitCells(array $cells, int $threshold): array
    {
        $visible = [];
        $thin = [];

        foreach ($cells as $cell) {
            if ($cell->recordCount < $threshold) {
                $thin[] = $cell;

                continue;
            }

            $visible[] = $cell;
        }

        while ($thin !== [] && $visible !== [] && $this->recordSum($thin) < $threshold) {
            $index = $this->smallestIndex($visible);
            $thin[] = $visible[$index];

            array_splice($visible, $index, 1);
        }

        return [$visible, $thin];
    }

    /**
     * @param  list<ReportGroupRowData>  $cells
     */
    private function smallestIndex(array $cells): int
    {
        $smallest = 0;

        foreach ($cells as $index => $cell) {
            if ($cell->recordCount < $cells[$smallest]->recordCount) {
                $smallest = $index;
            }
        }

        return $smallest;
    }

    /**
     * @param  list<ReportGroupRowData>  $cellRows
     * @return array<string, list<ReportGroupRowData>>
     */
    private function cellsByCategory(array $cellRows): array
    {
        $grouped = [];

        foreach ($cellRows as $row) {
            $grouped[$this->categoryKey($row->groupValue)][] = $row;
        }

        return $grouped;
    }

    private function categoryKey(?string $value): string
    {
        return $value === null ? "\0" : 'v'.$value;
    }

    private function rankCategories(ReportGroupRowData $left, ReportGroupRowData $right): int
    {
        $byValue = $this->compareDescending($left->value, $right->value);

        return $byValue !== 0 ? $byValue : $this->compareAscending($left->groupValue, $right->groupValue);
    }

    private function compareDescending(?string $left, ?string $right): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        return $this->compareAggregates($right, $left);
    }

    private function compareAscending(?string $left, ?string $right): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        return strcmp($left, $right);
    }

    private function compareAggregates(string $left, string $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            $leftDecimal = $this->decimalOrNull($left);
            $rightDecimal = $this->decimalOrNull($right);

            return $leftDecimal !== null && $rightDecimal !== null
                ? bccomp($leftDecimal, $rightDecimal, max($this->scaleOf($leftDecimal), $this->scaleOf($rightDecimal)))
                : (float) $left <=> (float) $right;
        }

        $leftMoment = strtotime($left);
        $rightMoment = strtotime($right);

        return $leftMoment !== false && $rightMoment !== false
            ? $leftMoment <=> $rightMoment
            : strcmp($left, $right);
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function sumOfValues(array $rows): ?string
    {
        $total = '0';
        $seen = false;

        foreach ($rows as $row) {
            $value = $this->decimalOrNull($row->value);

            if ($value === null) {
                continue;
            }

            $total = $this->addDecimals($total, $value);
            $seen = true;
        }

        return $seen ? $this->trimDecimal($total) : null;
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function meanOfValues(array $rows): ?string
    {
        $count = $this->valueCountOf($rows);

        return $count === 0
            ? null
            : $this->trimDecimal(bcdiv($this->valueSumOf($rows), (string) $count, 10));
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function extremeValue(array $rows, bool $lowest): ?string
    {
        $extreme = null;

        foreach ($rows as $row) {
            if ($row->value === null) {
                continue;
            }

            if ($extreme === null || ($this->compareAggregates($row->value, $extreme) < 0) === $lowest) {
                $extreme = $row->value;
            }
        }

        return $extreme;
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     * @return numeric-string
     */
    private function valueSumOf(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = $this->addDecimals($total, $row->valueSum);
        }

        return $total;
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function valueCountOf(array $rows): int
    {
        return (int) collect($rows)->sum('valueCount');
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function recordSum(array $rows): int
    {
        return (int) collect($rows)->sum('recordCount');
    }

    /**
     * @param  list<ReportGroupRowData>  $rows
     */
    private function discardedSum(array $rows): int
    {
        return (int) collect($rows)->sum('discardedValueCount');
    }

    private function isDecomposable(AggregationType $aggregation): bool
    {
        return $aggregation !== AggregationType::DistinctCount;
    }

    private function neutralTotal(AggregationType $aggregation, ?string $ungroupedValue): ?string
    {
        return match ($aggregation) {
            AggregationType::Count, AggregationType::Sum => '0',
            AggregationType::DistinctCount => $ungroupedValue ?? '0',
            AggregationType::Avg, AggregationType::Min, AggregationType::Max => null,
        };
    }

    /**
     * @param  numeric-string  $carry
     * @return numeric-string
     */
    private function addDecimals(string $carry, ?string $value): string
    {
        $decimal = $this->decimalOrNull($value);

        if ($decimal === null) {
            return $carry;
        }

        return bcadd($carry, $decimal, max($this->scaleOf($carry), $this->scaleOf($decimal)));
    }

    /**
     * @return numeric-string|null
     */
    private function decimalOrNull(?string $value): ?string
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1 ? $value : null;
    }

    private function scaleOf(string $value): int
    {
        $separator = strpos($value, '.');

        return $separator === false ? 0 : strlen($value) - $separator - 1;
    }

    private function trimDecimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }
}
