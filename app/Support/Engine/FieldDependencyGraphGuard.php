<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Models\FieldDefinition;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaReferenceCollector;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FieldDependencyGraphGuard
{
    public function __construct(
        private readonly FormulaParser $parser,
        private readonly FormulaReferenceCollector $collector,
        private readonly FilterFieldKeyCollector $filterFieldKeyCollector,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @throws ValidationException
     */
    public function guardAcyclic(FieldDefinition $field): void
    {
        $graph = new RollupGraph;

        $computedFields = $this->fieldLookup->computedFields((string) $field->object_type_id);

        foreach ($computedFields as $computedField) {
            if ($computedField->field_type === FieldType::Rollup) {
                $this->addRollupEdge($graph, $computedField);

                continue;
            }

            $this->addFormulaEdges($graph, $computedField);
        }

        $cycle = $graph->detectCycle();

        if ($cycle !== null) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.support.engine.field_dependency_graph_guard.the_fields_contain_circular_references').implode(' → ', $cycle).'.',
            ]);
        }
    }

    private function addRollupEdge(RollupGraph $graph, FieldDefinition $field): void
    {
        $source = $field->config['source_field_key'] ?? null;

        if (is_string($source) && $source !== '') {
            $graph->addEdge($field->key, $source);
        }

        $filter = $field->config['filter'] ?? null;

        if (!is_array($filter)) {
            return;
        }

        foreach ($this->filterFieldKeyCollector->collect($filter) as $filterFieldKey) {
            $graph->addEdge($field->key, $filterFieldKey);
        }
    }

    private function addFormulaEdges(RollupGraph $graph, FieldDefinition $field): void
    {
        $formula = $field->config['formula'] ?? null;

        if (!is_string($formula) || trim($formula) === '') {
            $this->logSkippedField($field, __('i18n.backend.support.engine.field_dependency_graph_guard.missing_or_empty_formula'));

            return;
        }

        try {
            $node = $this->parser->parse($formula);
        } catch (FormulaSyntaxException $exception) {
            $this->logSkippedField($field, $exception->getMessage());

            return;
        }

        $this->collector->addDependencyEdges($graph, $field->key, $node);
    }

    private function logSkippedField(FieldDefinition $field, string $reason): void
    {
        Log::warning('Skipped a computed field with an unusable formula while checking the field dependency graph.', [
            'field_id' => $field->getKey(),
            'field_key' => $field->key,
            'object_type_id' => $field->object_type_id,
            'reason' => $reason,
        ]);
    }
}
