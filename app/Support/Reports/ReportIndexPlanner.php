<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportDefinitionData;
use App\Enums\CustomFields\FieldType;
use App\Enums\Reports\AggregationType;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\SystemFilterFields;

class ReportIndexPlanner
{
    public function __construct(
        private readonly ReportExpressionCompiler $compiler,
        private readonly IndexRegistry $indexRegistry,
        private readonly SystemFilterFields $systemFields,
    ) {}

    /**
     * @return list<FieldDefinition>
     */
    public function plan(ReportDefinitionData $definition): array
    {
        /** @var array<string, FieldDefinition> $planned */
        $planned = [];

        foreach ($this->candidates($definition) as $field) {
            if (!$this->isIndexable($field)) {
                continue;
            }

            $planned[$field->key] ??= $field;
        }

        return array_values($planned);
    }

    public function isIndexable(FieldDefinition $field): bool
    {
        if ($field->is_encrypted || $field->is_translatable) {
            return false;
        }

        if ($this->systemFields->isSystemField($field)) {
            return false;
        }

        if ($field->field_type === FieldType::Date || $field->field_type === FieldType::DateTime) {
            return false;
        }

        if ($field->is_sortable || $field->is_filterable) {
            return false;
        }

        if (!$field->exists || $field->object_type_id === '') {
            return false;
        }

        return $this->compiler->groupingExpression($field) === $this->indexRegistry->sortExpression($field);
    }

    /**
     * @return list<FieldDefinition>
     */
    private function candidates(ReportDefinitionData $definition): array
    {
        $candidates = [];

        if ($definition->groupByField instanceof FieldDefinition
            && $definition->groupByBucket === null
            && $definition->groupByLink === null
        ) {
            $candidates[] = $definition->groupByField;
        }

        if ($definition->seriesField instanceof FieldDefinition) {
            $candidates[] = $definition->seriesField;
        }

        if ($definition->aggregationField instanceof FieldDefinition
            && $definition->aggregation === AggregationType::DistinctCount
            && $definition->aggregationLink === null
        ) {
            $candidates[] = $definition->aggregationField;
        }

        return $candidates;
    }
}
