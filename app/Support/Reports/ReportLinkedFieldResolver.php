<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Exceptions\Reports\UnsupportedAggregationException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReportLinkedFieldResolver
{
    public function __construct(
        private readonly ReportExpressionCompiler $expressions,
    ) {}

    public function isQualified(string $key): bool
    {
        return str_contains($key, '.');
    }

    /**
     * @throws ReportNotExecutableException
     */
    public function resolve(string $qualifiedKey, ObjectType $objectType): ReportLinkedFieldBinding
    {
        $objectTypeId = (string) $objectType->getKey();

        $relationKey = $this->safeSegment(Str::before($qualifiedKey, '.'), $qualifiedKey);
        $linkedKey = $this->safeSegment(Str::after($qualifiedKey, '.'), $qualifiedKey);

        $relationField = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('key', $relationKey)
            ->first();

        if (!$relationField instanceof FieldDefinition || !$this->isRelation($relationField->field_type)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
        }

        $config = $relationField->config ?? [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
        }

        $relationshipType = RelationshipType::query()->whereKey($relationshipTypeId)->first();

        if (!$relationshipType instanceof RelationshipType || $relationshipType->from_object_type_id !== $objectTypeId) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
        }

        $linkedField = FieldDefinition::query()
            ->where('object_type_id', $relationshipType->to_object_type_id)
            ->where('key', $linkedKey)
            ->first();

        if (!$linkedField instanceof FieldDefinition) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
        }

        return new ReportLinkedFieldBinding(
            $qualifiedKey,
            $relationField,
            (string) $relationshipType->getKey(),
            $relationshipType->to_object_type_id,
            $linkedField,
        );
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function groupSubquery(ReportLinkedFieldBinding $binding, ?GroupingBucket $bucket): QueryBuilder
    {
        $expression = $bucket === null
            ? $this->expressions->groupingExpression($binding->linkedField)
            : $this->expressions->bucketExpression($binding->linkedField, $bucket);

        return $this->correlated($binding)->selectRaw("MIN({$expression})");
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function aggregateSubquery(ReportLinkedFieldBinding $binding, AggregationType $aggregation): QueryBuilder
    {
        return $this->correlated($binding)->selectRaw(
            $this->expressions->aggregateExpression($binding->linkedField, $aggregation),
        );
    }

    /**
     * @throws UnsupportedAggregationException
     * @throws InvalidArgumentException
     */
    public function discardedSubquery(ReportLinkedFieldBinding $binding, AggregationType $aggregation): ?QueryBuilder
    {
        if (!$this->readsFieldValue($aggregation)) {
            return null;
        }

        $field = $binding->linkedField;

        $expression = $field->field_type->isTemporal()
            ? $this->expressions->discardedTemporalValueCountExpression($field)
            : $this->expressions->discardedNumericValueCountExpression($field);

        return $this->correlated($binding)->selectRaw($expression);
    }

    private function correlated(ReportLinkedFieldBinding $binding): QueryBuilder
    {
        $recordsTable = (string) config('engine.records_table');

        $linked = CustomRecord::query()->ofType($binding->linkedObjectTypeId);

        return DB::query()
            ->fromSub($linked, 'linked')
            ->whereIn('linked.id', function (QueryBuilder $links) use ($binding, $recordsTable): void {
                $links->select('record_links.to_record_id')
                    ->from('record_links')
                    ->whereColumn('record_links.from_record_id', "{$recordsTable}.id")
                    ->whereColumn('record_links.tenant_id', "{$recordsTable}.tenant_id")
                    ->where('record_links.relationship_type_id', $binding->relationshipTypeId);
            });
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function safeSegment(string $segment, string $qualifiedKey): string
    {
        if (substr_count($qualifiedKey, '.') !== 1 || preg_match('/^[a-z][a-z0-9_]*$/', $segment) !== 1) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
        }

        return $segment;
    }

    private function isRelation(FieldType $fieldType): bool
    {
        return $fieldType === FieldType::RelationHasMany || $fieldType === FieldType::RelationManyToMany;
    }

    private function readsFieldValue(AggregationType $aggregation): bool
    {
        return match ($aggregation) {
            AggregationType::Sum, AggregationType::Avg,
            AggregationType::Min, AggregationType::Max => true,
            AggregationType::Count, AggregationType::DistinctCount => false,
        };
    }
}
