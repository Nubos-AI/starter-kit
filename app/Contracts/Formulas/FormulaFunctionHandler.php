<?php

declare(strict_types=1);

namespace App\Contracts\Formulas;

use App\Enums\Formulas\FormulaFunction;

interface FormulaFunctionHandler
{
    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed;

    public function formulaFunction(): FormulaFunction;
}
