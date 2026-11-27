<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Exceptions\Reports\UnsupportedAggregationException;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\SystemFilterFields;
use App\Support\Sql\LiteralIdentifier;
use InvalidArgumentException;

class ReportExpressionCompiler
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly SystemFilterFields $systemFields,
        private readonly ?string $timeZone = null,
        private readonly LiteralIdentifier $literal = new LiteralIdentifier,
    ) {}

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function aggregateExpression(?FieldDefinition $field, AggregationType $aggregation): string
    {
        if ($field instanceof FieldDefinition) {
            $this->assertUsableField($field);
        }

        if ($aggregation === AggregationType::Count) {
            return 'COUNT(*)';
        }

        if (!$field instanceof FieldDefinition) {
            throw UnsupportedAggregationException::forMissingField($aggregation);
        }

        if (!$aggregation->allowsFieldType($field->field_type)) {
            throw UnsupportedAggregationException::forFieldType($aggregation, $field->field_type);
        }

        if ($aggregation === AggregationType::DistinctCount) {
            $grouping = $this->groupingExpression($field);

            return "COUNT(DISTINCT {$grouping})";
        }

        $function = $this->aggregateFunction($aggregation);
        $value = $field->field_type->isTemporal()
            ? $this->temporalValueExpression($field)
            : $this->numericValueExpression($field);

        return "{$function}({$value})";
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function groupingExpression(FieldDefinition $field): string
    {
        $this->assertUsableField($field);

        if ($this->systemFields->isSystemField($field)) {
            return (string) $this->systemFields->columnFor($field->key);
        }

        return $this->indexRegistry->sortExpression($field);
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function bucketExpression(FieldDefinition $field, GroupingBucket $bucket): string
    {
        $this->assertUsableField($field);
        $this->assertTemporalField($field);

        $unit = $this->bucketUnit($bucket);
        $value = $this->temporalValueExpression($field);
        $zone = $this->safeTimeZone();

        return "date_trunc('{$unit}', {$value}, '{$zone}')";
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function discardedNumericValueCountExpression(FieldDefinition $field): string
    {
        $this->assertUsableField($field);
        $this->assertJsonField($field);

        if (!AggregationType::Sum->allowsFieldType($field->field_type)) {
            throw UnsupportedAggregationException::forNonNumericField($field->field_type);
        }

        $present = $this->jsonValueExpression($field);
        $usable = $this->numericValueExpression($field);

        return "(COUNT({$present}) - COUNT({$usable}))";
    }

    /**
     * @return literal-string
     *
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function discardedTemporalValueCountExpression(FieldDefinition $field): string
    {
        $this->assertUsableField($field);
        $this->assertJsonField($field);
        $this->assertTemporalField($field);

        $present = $this->jsonValueExpression($field);
        $usable = $this->temporalValueExpression($field);

        return "(COUNT({$present}) - COUNT({$usable}))";
    }

    /**
     * @throws UnsupportedAggregationException
     */
    private function assertUsableField(FieldDefinition $field): void
    {
        if ($field->is_encrypted) {
            throw UnsupportedAggregationException::forEncryptedField();
        }

        if ($field->is_translatable) {
            throw UnsupportedAggregationException::forTranslatableField();
        }
    }

    /**
     * @throws UnsupportedAggregationException
     */
    private function assertTemporalField(FieldDefinition $field): void
    {
        if (!$field->field_type->isTemporal()) {
            throw UnsupportedAggregationException::forNonTemporalField($field->field_type);
        }
    }

    /**
     * @throws UnsupportedAggregationException
     */
    private function assertJsonField(FieldDefinition $field): void
    {
        if ($this->systemFields->isSystemField($field)) {
            throw UnsupportedAggregationException::forSystemField();
        }
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function jsonValueExpression(FieldDefinition $field): string
    {
        $key = $this->indexRegistry->safeKey($field->key);

        return "data->>'{$key}'";
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function numericValueExpression(FieldDefinition $field): string
    {
        return $this->indexRegistry->indexExpression(
            $this->numericSurrogate(),
            $this->indexRegistry->safeKey($field->key),
        );
    }

    private function numericSurrogate(): FieldDefinition
    {
        $surrogate = new FieldDefinition;
        $surrogate->field_type = FieldType::Decimal;

        return $surrogate;
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function temporalValueExpression(FieldDefinition $field): string
    {
        $value = $this->jsonValueExpression($field);
        $pattern = '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])([T ][0-9]{2}:[0-9]{2}(:[0-9]{2}(\.[0-9]+)?)?(Z|[+-][0-9]{2}:?[0-9]{2})?)?$';

        if ($field->field_type === FieldType::Date) {
            $zone = $this->safeTimeZone();

            return "(CASE WHEN {$value} ~ '{$pattern}' AND pg_input_is_valid({$value}, 'timestamp') THEN (({$value})::timestamp AT TIME ZONE '{$zone}') END)";
        }

        return "(CASE WHEN {$value} ~ '{$pattern}' AND pg_input_is_valid({$value}, 'timestamptz') THEN ({$value})::timestamptz END)";
    }

    /**
     * @return literal-string
     */
    private function aggregateFunction(AggregationType $aggregation): string
    {
        return match ($aggregation) {
            AggregationType::Count, AggregationType::DistinctCount => 'COUNT',
            AggregationType::Sum => 'SUM',
            AggregationType::Avg => 'AVG',
            AggregationType::Min => 'MIN',
            AggregationType::Max => 'MAX',
        };
    }

    /**
     * @return literal-string
     */
    private function bucketUnit(GroupingBucket $bucket): string
    {
        return match ($bucket) {
            GroupingBucket::Day => 'day',
            GroupingBucket::Week => 'week',
            GroupingBucket::Month => 'month',
            GroupingBucket::Quarter => 'quarter',
            GroupingBucket::Year => 'year',
        };
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    private function safeTimeZone(): string
    {
        return $this->literal->timeZone($this->timeZone ?? (string) config('reports.timezone'));
    }
}
