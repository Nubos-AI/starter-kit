<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use DateTimeImmutable;

class FormulaValueCoercer
{
    private string $decimalPattern = '/^-?\d+(\.\d+)?$/D';

    /**
     * @return numeric-string|FormulaErrorValue
     */
    public function toDecimalString(mixed $value, int $scale): string|FormulaErrorValue
    {
        if (is_float($value) && !is_finite($value)) {
            return new FormulaErrorValue(FormulaErrorCode::NotANumber);
        }

        $candidate = match (true) {
            is_int($value) => (string) $value,
            is_float($value) => sprintf('%.'.$scale.'F', $value),
            default => $value,
        };

        return $this->decimalOrNull($candidate) ?? new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
    }

    public function toBoolean(mixed $value): bool|FormulaErrorValue
    {
        return is_bool($value) ? $value : new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
    }

    public function toText(mixed $value, int $scale): string|FormulaErrorValue
    {
        $decimal = $this->toDecimalString($value, $scale);

        if ($decimal instanceof FormulaErrorValue) {
            return is_string($value) ? $value : $decimal;
        }

        return $this->stripTrailingZeros($decimal);
    }

    public function toDate(mixed $value): string|FormulaErrorValue
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return new FormulaErrorValue(FormulaErrorCode::InvalidDate);
        }

        return $date->format('Y-m-d');
    }

    public function toWorkingValue(mixed $value, int $scale): string|bool|FormulaErrorValue
    {
        if (is_bool($value) || $value instanceof FormulaErrorValue) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $this->toDecimalString($value, $scale);
        }

        return is_string($value) ? $value : new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
    }

    public function toExactValue(string|bool|FormulaErrorValue $value): string|bool|FormulaErrorValue
    {
        if (!is_string($value)) {
            return $value;
        }

        $decimal = $this->decimalOrNull($value);

        return $decimal === null ? $value : $this->stripTrailingZeros($decimal);
    }

    /**
     * @return numeric-string|null
     */
    private function decimalOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match($this->decimalPattern, $value) === 1 && is_numeric($value)
            ? $value
            : null;
    }

    private function stripTrailingZeros(string $value): string
    {
        $trimmed = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return $trimmed === '-0' ? '0' : $trimmed;
    }
}
