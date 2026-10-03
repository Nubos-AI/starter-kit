<?php

declare(strict_types=1);

namespace App\Exceptions\Formulas;

use RuntimeException;

class FormulaSyntaxException extends RuntimeException
{
    public function __construct(string $message, public readonly int $position)
    {
        parent::__construct($message);
    }

    public static function tooLong(int $length, int $maximum): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_formula_is_characters_long_and_exceeds_the_allowed', ['value1' => $length, 'value2' => $maximum]),
            0,
        );
    }

    public static function doubleBrace(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_double_brace_at_position_belongs_to_the_template', ['value1' => $position]),
            $position,
        );
    }

    public static function unterminatedFieldReference(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_field_reference_opened_at_position_is_never_closed', ['value1' => $position]),
            $position,
        );
    }

    public static function invalidFieldKey(string $key, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_field_reference_at_position_uses_the_invalid_identifier', ['value1' => $position, 'value2' => $key]),
            $position,
        );
    }

    public static function unterminatedText(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_text_opened_at_position_is_never_closed_with', ['value1' => $position]),
            $position,
        );
    }

    public static function duplicateDecimalSeparator(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_number_has_a_second_decimal_separator_at_position', ['value1' => $position]),
            $position,
        );
    }

    public static function trailingDecimalSeparator(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_number_ends_with_a_decimal_separator_at_position', ['value1' => $position]),
            $position,
        );
    }

    public static function digitGroupSeparator(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_digits_at_position_are_separated_from_the_preceding', ['value1' => $position]),
            $position,
        );
    }

    public static function unexpectedCharacter(string $character, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_character_at_position_is_not_part_of_the', ['value1' => $character, 'value2' => $position]),
            $position,
        );
    }

    public static function emptyFormula(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_formula_ends_at_position_without_containing_a_single', ['value1' => $position]),
            $position,
        );
    }

    public static function missingOperand(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_formula_ends_at_position_while_an_operand_is', ['value1' => $position]),
            $position,
        );
    }

    public static function unexpectedToken(string $text, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_expression_is_not_allowed_at_position', ['value1' => $text, 'value2' => $position]),
            $position,
        );
    }

    public static function unclosedParenthesis(int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_parenthesis_opened_at_position_is_never_closed', ['value1' => $position]),
            $position,
        );
    }

    public static function unknownFunction(string $name, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_function_at_position_is_not_in_the_formula', ['value1' => $name, 'value2' => $position]),
            $position,
        );
    }

    public static function functionCallExpected(string $name, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_catalogue_name_at_position_must_be_followed_by', ['value1' => $name, 'value2' => $position]),
            $position,
        );
    }

    public static function argumentCountMismatch(string $function, int $given, int $minimum, ?int $maximum, int $position): self
    {
        $accepted = match (true) {
            $maximum === null => "mindestens {$minimum}",
            $minimum === $maximum => "genau {$minimum}",
            default => "zwischen {$minimum} und {$maximum}",
        };

        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_function_at_position_takes_arguments_but_received', ['value1' => $function, 'value2' => $position, 'value3' => $accepted, 'value4' => $given]),
            $position,
        );
    }

    public static function nestingTooDeep(int $maximum, int $position): self
    {
        return new self(
            __('i18n.backend.exceptions.formulas.formula_syntax_exception.the_formula_at_position_exceeds_the_allowed_nesting_depth', ['value1' => $position, 'value2' => $maximum]),
            $position,
        );
    }
}
