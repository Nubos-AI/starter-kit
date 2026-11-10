<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

enum FormulaErrorCode: string
{
    case DivisionByZero = 'division_by_zero';

    case UnknownFieldReference = 'unknown_field_reference';

    case TypeMismatch = 'type_mismatch';

    case InvalidArgumentCount = 'invalid_argument_count';

    case NotANumber = 'not_a_number';

    case InvalidDate = 'invalid_date';

    case EvaluationLimitExceeded = 'evaluation_limit_exceeded';

    case InvalidConfiguration = 'invalid_configuration';

    public function label(): string
    {
        return match ($this) {
            self::DivisionByZero => __('i18n.backend.enums.formulas.formula_error_code.division_by_zero'),
            self::UnknownFieldReference => __('i18n.backend.enums.formulas.formula_error_code.the_formula_refers_to_a_field_that_does_not'),
            self::TypeMismatch => __('i18n.backend.enums.formulas.formula_error_code.a_value_does_not_match_the_expected_type'),
            self::InvalidArgumentCount => __('i18n.backend.enums.formulas.formula_error_code.a_function_received_the_wrong_number_of_arguments'),
            self::NotANumber => __('i18n.backend.enums.formulas.formula_error_code.a_value_could_not_be_read_as_a_number'),
            self::InvalidDate => __('i18n.backend.enums.formulas.formula_error_code.a_value_could_not_be_read_as_a_date'),
            self::EvaluationLimitExceeded => __('i18n.backend.enums.formulas.formula_error_code.the_formula_exceeded_the_maximum_number_of_evaluation_steps'),
            self::InvalidConfiguration => __('i18n.backend.enums.formulas.formula_error_code.the_formula_field_is_not_configured_with_a_usable'),
        };
    }
}
