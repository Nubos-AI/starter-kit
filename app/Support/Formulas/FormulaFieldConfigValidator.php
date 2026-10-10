<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\FormulaTypeIssue;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Models\FieldDefinition;
use Illuminate\Validation\ValidationException;

class FormulaFieldConfigValidator
{
    public function __construct(
        private readonly FormulaParser $parser,
        private readonly FormulaTypeChecker $typeChecker,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validate(FieldDefinition $field): FormulaNode
    {
        $config = $field->config;

        if (!is_array($config)) {
            $this->reject(__('i18n.backend.support.formulas.formula_field_config_validator.a_formula_field_requires_settings_containing_a_formula_and'));
        }

        $formula = $config['formula'] ?? null;

        if (!is_string($formula) || trim($formula) === '') {
            $this->reject(__('i18n.backend.support.formulas.formula_field_config_validator.the_settings_require_a_formula'));
        }

        $resultType = $field->resultType();

        if ($resultType === null) {
            $accepted = implode(', ', array_column(FormulaValueType::cases(), 'value'));

            $this->reject(__('i18n.backend.support.formulas.formula_field_config_validator.the_settings_require_a_result_type_allowed_values_are', ['value1' => $accepted]));
        }

        try {
            $node = $this->parser->parse($formula);
        } catch (FormulaSyntaxException $exception) {
            $this->reject($exception->getMessage());
        }

        $issues = $this->typeChecker->check($node, $field->object_type_id, $resultType);

        if ($issues !== []) {
            throw ValidationException::withMessages([
                'config' => array_map(static fn (FormulaTypeIssue $issue): string => $issue->message(), $issues),
            ]);
        }

        return $node;
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['config' => $message]);
    }
}
