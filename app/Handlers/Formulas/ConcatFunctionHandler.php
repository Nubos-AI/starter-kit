<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;

class ConcatFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Concat;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        $text = '';

        foreach ($arguments as $argument) {
            $part = $this->readText($argument);

            if ($part === null) {
                return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
            }

            $text .= $part;
        }

        return $text;
    }
}
