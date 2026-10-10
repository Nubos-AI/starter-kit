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
use Illuminate\Support\Facades\DB;
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

    public function compute(FieldDefinition $field, CustomRecord $record): string|bool|FormulaErrorValue
    {
        $resultType = $field->resultType();
        $formula = $field->config['formula'] ?? null;

        if ($resultType === null || !is_string($formula) || trim($formula) === '') {
            return new FormulaErrorValue(FormulaErrorCode::InvalidConfiguration);
        }

        try {
            $result = $this->evaluator->evaluateExact(
                $this->parser->parse($formula),
                $this->contextFor($field, $record),
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
     * @throws JsonException
     */
    public function materialize(FieldDefinition $field, CustomRecord $record): string|bool|FormulaErrorValue
    {
        $value = $this->compute($field, $record);

        [$expression, $binding] = $this->writeFormFor($value, $field);

        $base = "(case when jsonb_typeof(data) = 'object' then data else '{}'::jsonb end)";

        DB::update(
            "UPDATE custom_records SET data = jsonb_set({$base}, ?, {$expression}, true) WHERE id = ? AND tenant_id = ?",
            [
                '{'.$field->key.'}',
                $binding,
                (string) $record->getKey(),
                (string) $record->getAttribute('tenant_id'),
            ],
        );

        return $value;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws JsonException
     */
    private function writeFormFor(string|bool|FormulaErrorValue $value, FieldDefinition $field): array
    {
        if ($value instanceof FormulaErrorValue) {
            return ['?::jsonb', json_encode($value->toArray(), JSON_THROW_ON_ERROR)];
        }

        if (is_bool($value)) {
            return ['to_jsonb(?::boolean)', $value ? 'true' : 'false'];
        }

        return $field->resultType() === FormulaValueType::Number
            ? ['to_jsonb(?::numeric)', $value]
            : ['to_jsonb(?::text)', $value];
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

    private function contextFor(FieldDefinition $field, CustomRecord $record): FormulaEvaluationContext
    {
        $stored = is_array($record->data) ? $record->data : [];
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
