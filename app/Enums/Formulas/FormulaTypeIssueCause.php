<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

enum FormulaTypeIssueCause: string
{
    case ResultTypeMismatch = 'result_type_mismatch';

    case ArgumentTypeMismatch = 'argument_type_mismatch';

    case OperandTypeMismatch = 'operand_type_mismatch';

    case BranchTypeMismatch = 'branch_type_mismatch';

    case UnknownFieldReference = 'unknown_field_reference';

    case EncryptedFieldReference = 'encrypted_field_reference';

    case UnsupportedFieldType = 'unsupported_field_type';

    case UnconfiguredResultType = 'unconfigured_result_type';

    public function label(): string
    {
        return match ($this) {
            self::ResultTypeMismatch => __('i18n.backend.enums.formulas.formula_type_issue_cause.the_formula_does_not_produce_the_configured_result_type'),
            self::ArgumentTypeMismatch => __('i18n.backend.enums.formulas.formula_type_issue_cause.an_argument_does_not_match_the_function_signature'),
            self::OperandTypeMismatch => __('i18n.backend.enums.formulas.formula_type_issue_cause.an_operand_does_not_match_the_operator'),
            self::BranchTypeMismatch => __('i18n.backend.enums.formulas.formula_type_issue_cause.the_conditional_branches_produce_different_types'),
            self::UnknownFieldReference => __('i18n.backend.enums.formulas.formula_type_issue_cause.the_referenced_field_does_not_exist_on_this_object'),
            self::EncryptedFieldReference => __('i18n.backend.enums.formulas.formula_type_issue_cause.an_encrypted_field_cannot_be_referenced_in_a_formula'),
            self::UnsupportedFieldType => __('i18n.backend.enums.formulas.formula_type_issue_cause.the_referenced_field_type_cannot_be_used_in_a'),
            self::UnconfiguredResultType => __('i18n.backend.enums.formulas.formula_type_issue_cause.the_referenced_computed_field_has_no_usable_result_type'),
        };
    }
}
