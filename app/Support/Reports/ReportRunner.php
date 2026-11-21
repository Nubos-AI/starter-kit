<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Contracts\Modules\ReportQuerySourceInterface;
use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportGroupRowData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Exceptions\Reports\UnsupportedAggregationException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\RecordFilterCompiler;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ReportRunner
{
    /** @param iterable<ReportQuerySourceInterface> $sources */
    public function __construct(
        private readonly ReportExpressionCompiler $expressions,
        private readonly RecordFilterCompiler $filters,
        private readonly ReportResultAssembler $assembler,
        private readonly ReportLinkedFieldResolver $linkedFields,
        #[Tag(ReportQuerySourceInterface::class)] private readonly iterable $sources = [],
    ) {}

    /**
     * @throws ReportNotExecutableException
     */
    public function run(ReportDefinitionData $definition): ReportResultData
    {
        $generatedAt = CarbonImmutable::now()->toIso8601String();

        try {
            [$cellRows, $rawValues] = $this->fetchRows($definition, $definition->seriesField);

            $categoryRows = $cellRows;

            if ($definition->seriesField instanceof FieldDefinition) {
                [$categoryRows, $categoryRawValues] = $this->fetchRows($definition, null);
                $rawValues += $categoryRawValues;
            }

            $ungroupedValue = $definition->aggregation === AggregationType::DistinctCount
                ? $this->fetchUngroupedValue($definition)
                : null;
        } catch (InvalidArgumentException|UnsupportedAggregationException $exception) {
            throw new ReportNotExecutableException(
                ReportNotExecutableReason::UnsupportedAggregation,
                $exception,
            );
        }

        return $this->assembler->assemble(
            $definition,
            $categoryRows,
            $cellRows,
            $generatedAt,
            $ungroupedValue,
            fn (array $groups): array => $this->resolveFoldedValues($definition, $groups, $rawValues),
        );
    }

    /**
     * @throws ReportNotExecutableException
     */
    public function aggregateQuery(ReportDefinitionData $definition): QueryBuilder
    {
        try {
            return $this->groupedQuery($definition, $definition->seriesField);
        } catch (InvalidArgumentException|UnsupportedAggregationException $exception) {
            throw new ReportNotExecutableException(
                ReportNotExecutableReason::UnsupportedAggregation,
                $exception,
            );
        }
    }

    /**
     * @return array{list<ReportGroupRowData>, array<int, array{ReportGroupRowData, ?string, ?string}>}
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function fetchRows(ReportDefinitionData $definition, ?FieldDefinition $seriesField): array
    {
        $bucketed = $definition->groupByBucket !== null;
        $temporal = $this->readsTemporalValue($definition);

        $rows = [];
        $rawValues = [];

        foreach ($this->groupedQuery($definition, $seriesField)->get() as $record) {
            $columns = (array) $record;

            $rawGroup = $this->text($columns['group_value'] ?? null);
            $rawSeries = $this->text($columns['series_value'] ?? null);
            $rawValue = $this->text($columns['aggregate_value'] ?? null);

            $row = new ReportGroupRowData(
                $bucketed ? $this->normalise($rawGroup) : $rawGroup,
                $rawSeries,
                $temporal ? $this->normalise($rawValue) : $rawValue,
                $this->integer($columns['record_count'] ?? null),
                $this->text($columns['value_sum'] ?? null),
                array_key_exists('value_count', $columns) ? $this->integer($columns['value_count']) : null,
                $this->integer($columns['discarded_count'] ?? null),
            );

            $rows[] = $row;
            $rawValues[spl_object_id($row)] = [$row, $rawGroup, $rawSeries];
        }

        return [$rows, $rawValues];
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function fetchUngroupedValue(ReportDefinitionData $definition): ?string
    {
        return $this->scalarAggregate($this->baseQuery($definition), $this->aggregateExpression($definition));
    }

    /**
     * @param  literal-string  $aggregate
     */
    private function scalarAggregate(QueryBuilder $query, string $aggregate): ?string
    {
        $record = $query->selectRaw("{$aggregate} AS aggregate_total")->first();

        $columns = $record === null ? [] : (array) $record;

        return $this->text($columns['aggregate_total'] ?? null);
    }

    /**
     * @param  list<list<ReportGroupRowData>>  $groups
     * @param  array<int, array{ReportGroupRowData, ?string, ?string}>  $rawValues
     * @return list<?string>
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function resolveFoldedValues(ReportDefinitionData $definition, array $groups, array $rawValues): array
    {
        $groupExpression = $this->groupExpression($definition);

        if ($groups === [] || $groupExpression === null) {
            return array_fill(0, count($groups), null);
        }

        $aggregate = $this->aggregateExpression($definition);

        $seriesExpression = $definition->seriesField instanceof FieldDefinition
            ? $this->expressions->groupingExpression($definition->seriesField)
            : null;

        $values = [];

        foreach ($groups as $rows) {
            $predicates = [];
            $bindings = [];

            foreach ($rows as $row) {
                $entry = $rawValues[spl_object_id($row)] ?? null;

                if ($entry === null || $entry[0] !== $row) {
                    throw new LogicException(
                        __('i18n.backend.support.reports.report_runner.a_report_row_reached_the_collector_resolver_without_its'),
                    );
                }

                $predicate = "{$groupExpression} IS NOT DISTINCT FROM ?";
                $bindings[] = $entry[1];

                if ($seriesExpression !== null) {
                    $predicate .= " AND {$seriesExpression} IS NOT DISTINCT FROM ?";
                    $bindings[] = $entry[2];
                }

                $predicates[] = "({$predicate})";
            }

            if ($predicates === []) {
                $values[] = null;

                continue;
            }

            $values[] = $this->scalarAggregate(
                $this->baseQuery($definition)->whereRaw('('.implode(' OR ', $predicates).')', $bindings),
                $aggregate,
            );
        }

        return $values;
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function groupedQuery(ReportDefinitionData $definition, ?FieldDefinition $seriesField): QueryBuilder
    {
        $query = $this->baseQuery($definition);

        $selects = [];
        $groupings = [];
        $orderings = [];

        $groupExpression = $this->groupExpression($definition);

        if ($groupExpression !== null) {
            $selects[] = "{$groupExpression} AS group_value";
            $groupings[] = $groupExpression;
            $orderings[] = "{$groupExpression} ASC NULLS LAST";
        }

        if ($seriesField instanceof FieldDefinition) {
            $seriesExpression = $this->expressions->groupingExpression($seriesField);
            $selects[] = "{$seriesExpression} AS series_value";
            $groupings[] = $seriesExpression;
            $orderings[] = "{$seriesExpression} ASC NULLS LAST";
        }

        $selects[] = $this->aggregateExpression($definition).' AS aggregate_value';

        $selects[] = 'COUNT(*) AS record_count';

        $field = $definition->aggregationField;

        if ($definition->aggregation === AggregationType::Avg && $field instanceof FieldDefinition) {
            $valueSum = $this->expressions->aggregateExpression($field, AggregationType::Sum);

            $selects[] = "{$valueSum} AS value_sum";
            $selects[] = $this->usableValueCountExpression($field).' AS value_count';
        }

        $discarded = $this->discardedExpression($definition);

        if ($discarded !== null) {
            $selects[] = "{$discarded} AS discarded_count";
        }

        $query->selectRaw(implode(', ', $selects));

        if ($groupings !== []) {
            $query
                ->groupByRaw(implode(', ', $groupings))
                ->orderByRaw(implode(', ', $orderings));
        }

        return $query;
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function baseQuery(ReportDefinitionData $definition): QueryBuilder
    {
        foreach ($this->sources as $source) {
            $query = $source->query($definition);
            if ($query !== null) {
                return $query;
            }
        }
        $query = CustomRecord::query()->ofType($definition->objectTypeId);

        $this->filters->applyValidatedTree(
            $query,
            new Collection(array_values($definition->fields)),
            $definition->filterTree,
        );

        return $this->withLinkedColumns($definition, $query->toBase());
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function withLinkedColumns(ReportDefinitionData $definition, QueryBuilder $primary): QueryBuilder
    {
        $groupLink = $definition->groupByLink;
        $aggregateLink = $this->linkedAggregateFunction($definition) === null ? null : $definition->aggregationLink;

        if (!$groupLink instanceof ReportLinkedFieldBinding && !$aggregateLink instanceof ReportLinkedFieldBinding) {
            return $primary;
        }

        $recordsTable = (string) config('engine.records_table');

        $primary->select("{$recordsTable}.*");

        if ($groupLink instanceof ReportLinkedFieldBinding) {
            $primary->selectSub(
                $this->linkedFields->groupSubquery($groupLink, $definition->groupByBucket),
                'linked_group_value',
            );
        }

        if ($aggregateLink instanceof ReportLinkedFieldBinding) {
            $primary->selectSub(
                $this->linkedFields->aggregateSubquery($aggregateLink, $definition->aggregation),
                'linked_aggregate_value',
            );

            $discarded = $this->linkedFields->discardedSubquery($aggregateLink, $definition->aggregation);

            if ($discarded instanceof QueryBuilder) {
                $primary->selectSub($discarded, 'linked_discarded_value');
            }
        }

        return DB::query()->fromSub($primary, $recordsTable);
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function aggregateExpression(ReportDefinitionData $definition): string
    {
        return $this->linkedAggregateFunction($definition)
            ?? $this->expressions->aggregateExpression($definition->aggregationField, $definition->aggregation);
    }

    /**
     * @return literal-string|null
     */
    private function linkedAggregateFunction(ReportDefinitionData $definition): ?string
    {
        if (!$definition->aggregationLink instanceof ReportLinkedFieldBinding) {
            return null;
        }

        return match ($definition->aggregation) {
            AggregationType::Sum => 'SUM(linked_aggregate_value)',
            AggregationType::Min => 'MIN(linked_aggregate_value)',
            AggregationType::Max => 'MAX(linked_aggregate_value)',
            AggregationType::Count, AggregationType::Avg, AggregationType::DistinctCount => null,
        };
    }

    /**
     * @return literal-string|null
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function groupExpression(ReportDefinitionData $definition): ?string
    {
        if ($definition->groupByLink instanceof ReportLinkedFieldBinding) {
            return 'linked_group_value';
        }

        $field = $definition->groupByField;

        if (!$field instanceof FieldDefinition) {
            return null;
        }

        return $definition->groupByBucket === null
            ? $this->expressions->groupingExpression($field)
            : $this->expressions->bucketExpression($field, $definition->groupByBucket);
    }

    /**
     * @return literal-string|null
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function discardedExpression(ReportDefinitionData $definition): ?string
    {
        if ($this->linkedAggregateFunction($definition) !== null) {
            return 'SUM(linked_discarded_value)';
        }

        $field = $definition->aggregationField;

        if (!$field instanceof FieldDefinition || $definition->isSystemField($field->key)) {
            return null;
        }

        if (!$this->readsFieldValue($definition->aggregation)) {
            return null;
        }

        return $field->field_type->isTemporal()
            ? $this->expressions->discardedTemporalValueCountExpression($field)
            : $this->expressions->discardedNumericValueCountExpression($field);
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    private function usableValueCountExpression(FieldDefinition $field): string
    {
        $present = $this->expressions->groupingExpression($field);

        if ($field->usesNumericIndex()) {
            return "COUNT({$present})";
        }

        $discarded = $this->expressions->discardedNumericValueCountExpression($field);

        return "(COUNT({$present}) - {$discarded})";
    }

    private function readsFieldValue(AggregationType $aggregation): bool
    {
        return match ($aggregation) {
            AggregationType::Sum, AggregationType::Avg,
            AggregationType::Min, AggregationType::Max => true,
            AggregationType::Count, AggregationType::DistinctCount => false,
        };
    }

    private function readsTemporalValue(ReportDefinitionData $definition): bool
    {
        $field = $definition->aggregationField;

        if (!$field instanceof FieldDefinition) {
            return false;
        }

        $isExtremum = $definition->aggregation === AggregationType::Min
            || $definition->aggregation === AggregationType::Max;

        return $isExtremum && $field->field_type->isTemporal();
    }

    private function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)
            ->setTimezone((string) config('reports.timezone'))
            ->toIso8601String();
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
