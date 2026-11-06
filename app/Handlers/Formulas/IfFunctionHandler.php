<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class IfFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::IfThenElse;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        $condition = $arguments[0];

        if (!is_bool($condition)) {
            return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
        }

        return $condition ? $arguments[1] : $arguments[2];
    }

    /**
     * @param  list<mixed>  $arguments
     * @return list<mixed>
     */
    protected function errorPropagatingArguments(array $arguments): array
    {
        return array_slice($arguments, 0, 1);
    }
}
