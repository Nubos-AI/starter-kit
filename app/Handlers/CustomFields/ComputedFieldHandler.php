<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\DTOs\Formulas\FormulaErrorValue;
use App\DTOs\Formulas\FormulaEvaluationContext;
use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaValueType;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Formulas\FormulaEvaluator;
use App\Support\Formulas\FormulaFieldTypeMapper;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaValueCoercer;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class ComputedFieldHandler extends AbstractFieldHandler
{
    public function __construct(
        private readonly FormulaParser $parser,
        private readonly FormulaEvaluator $evaluator,
        private readonly FormulaValueCoercer $coercer,
        private readonly FormulaFieldTypeMapper $typeMapper,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    public function fieldType(): FieldType
    {
        return FieldType::Computed;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return $field->resultType()?->filterOperators() ?? [];
    }

    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return ['nullable'];
    }

    /**
     * @param  array<string, mixed>  $pendingValues
     */
    public function compute(FieldDefinition $field, CustomRecord $record, array $pendingValues = []): string|bool|FormulaErrorValue
    {
        $resultType = $field->resultType();
        $formula = $field->config['formula'] ?? null;

        if ($resultType === null || !is_string($formula) || trim($formula) === '') {
            return new FormulaErrorValue(FormulaErrorCode::InvalidConfiguration);
        }

        try {
            $result = $this->evaluator->evaluateExact(
                $this->parser->parse($formula),
                $this->contextFor($field, $record, $pendingValues),
            );

            return $result instanceof FormulaErrorValue
                ? $result
                : $this->asResultType($result, $resultType);
        } catch (Throwable $exception) {
            Log::warning('Computed field evaluation failed.', [
                'field_definition_id' => $field->getKey(),
                'record_id' => $record->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new FormulaErrorValue(FormulaErrorCode::InvalidConfiguration);
        }
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     *
     * @throws JsonException
     */
    public function jsonExpression(string|bool|FormulaErrorValue $value, FieldDefinition $field): array
    {
        if ($value instanceof FormulaErrorValue) {
            return ['sql' => '?::jsonb', 'bindings' => [json_encode($value->toArray(), JSON_THROW_ON_ERROR)]];
        }

        if (is_bool($value)) {
            return ['sql' => 'to_jsonb(?::boolean)', 'bindings' => [$value ? 'true' : 'false']];
        }

        return $field->resultType() === FormulaValueType::Number
            ? ['sql' => 'to_jsonb(?::numeric)', 'bindings' => [$value]]
            : ['sql' => 'to_jsonb(?::text)', 'bindings' => [$value]];
    }

    public function storedValue(string|bool|FormulaErrorValue $value, FieldDefinition $field): mixed
    {
        if ($value instanceof FormulaErrorValue) {
            return $value->toArray();
        }

        if (is_string($value) && is_numeric($value) && $field->resultType() === FormulaValueType::Number) {
            return $value + 0;
        }

        return $value;
    }

    private function asResultType(string|bool $result, FormulaValueType $resultType): string|bool|FormulaErrorValue
    {
        $scale = (int) config('formulas.scale');

        return match ($resultType) {
            FormulaValueType::Number => $this->coercer->toDecimalString($result, $scale),
            FormulaValueType::Text => $this->coercer->toText($result, $scale),
            FormulaValueType::Date => $this->coercer->toDate($result),
            FormulaValueType::Boolean => $this->coercer->toBoolean($result),
        };
    }

    /**
     * @param  array<string, mixed>  $pendingValues
     */
    private function contextFor(FieldDefinition $field, CustomRecord $record, array $pendingValues): FormulaEvaluationContext
    {
        $stored = array_replace(is_array($record->data) ? $record->data : [], $pendingValues);
        $values = [];
        $types = [];

        foreach ($this->fieldLookup->fields((string) $field->object_type_id) as $definition) {
            $key = $definition->key;
            $values[$key] = $stored[$key] ?? null;
            $types[$key] = $this->typeMapper->map($definition);
        }

        return new FormulaEvaluationContext($values, $types);
    }
}
