<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class SumFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Sum;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        $scale = (int) config('formulas.scale');
        $sum = '0';

        foreach ($arguments as $argument) {
            if ($argument === null) {
                return new FormulaErrorValue(FormulaErrorCode::NotANumber);
            }

            $decimal = $this->readDecimal($argument);

            if ($decimal === null) {
                return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
            }

            $sum = bcadd($sum, $decimal, $scale);
        }

        return $this->trimDecimal($sum);
    }
}
