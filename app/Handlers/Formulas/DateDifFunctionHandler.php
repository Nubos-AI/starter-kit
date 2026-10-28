<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class DateDifFunctionHandler extends AbstractFormulaFunctionHandler
{
    /**
     * @var list<string>
     */
    private array $dayUnits = ['d', 'days'];

    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::DateDif;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        $unit = $arguments[2];

        if (!is_string($unit) || !in_array(strtolower(trim($unit)), $this->dayUnits, true)) {
            return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
        }

        $start = $this->readDate($arguments[0]);
        $end = $this->readDate($arguments[1]);

        if ($start === null || $end === null) {
            return new FormulaErrorValue(FormulaErrorCode::InvalidDate);
        }

        return $start->diff($end)->format('%r%a');
    }
}
