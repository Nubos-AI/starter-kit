<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\Enums\Formulas\FormulaFunction;

class RecordingFormulaFunctionHandler implements FormulaFunctionHandler
{
    /**
     * @var list<list<mixed>>
     */
    public array $invocations = [];

    public mixed $result = 0;

    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed
    {
        $this->invocations[] = $arguments;

        return $this->result;
    }

    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Sum;
    }
}
