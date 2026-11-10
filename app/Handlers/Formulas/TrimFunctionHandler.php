<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class TrimFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Trim;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        $text = $this->readText($arguments[0] ?? null);

        return $text === null ? new FormulaErrorValue(FormulaErrorCode::TypeMismatch) : trim($text);
    }
}
