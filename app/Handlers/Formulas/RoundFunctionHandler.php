<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class RoundFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Round;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        if ($arguments[0] === null || $arguments[1] === null) {
            return new FormulaErrorValue(FormulaErrorCode::NotANumber);
        }

        $value = $this->readDecimal($arguments[0]);
        $precision = $this->readPrecision($arguments[1]);

        if ($value === null || $precision === null) {
            return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
        }

        return $this->trimDecimal($this->roundHalfAwayFromZero($value, $precision));
    }

    private function readPrecision(mixed $value): ?int
    {
        $decimal = $this->readDecimal($value);

        if ($decimal === null) {
            return null;
        }

        $scale = (int) config('formulas.scale');

        if (bccomp(bcmod($decimal, '1', $scale), '0', $scale) !== 0) {
            return null;
        }

        if (bccomp($decimal, (string) $scale, 0) > 0 || bccomp($decimal, (string) -$scale, 0) < 0) {
            return null;
        }

        return (int) $decimal;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function roundHalfAwayFromZero(string $value, int $precision): string
    {
        $scale = (int) config('formulas.scale');
        $factor = bcpow('10', (string) abs($precision));

        $shifted = $precision >= 0
            ? bcmul($value, $factor, $scale)
            : bcdiv($value, $factor, $scale);

        $offset = str_starts_with($shifted, '-') ? '-0.5' : '0.5';
        $truncated = bcdiv(bcadd($shifted, $offset, $scale), '1', 0);

        return $precision >= 0
            ? bcdiv($truncated, $factor, $scale)
            : bcmul($truncated, $factor, 0);
    }
}
