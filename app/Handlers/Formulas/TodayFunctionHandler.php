<?php

declare(strict_types=1);

namespace App\Handlers\Formulas;

use App\Enums\Formulas\FormulaFunction;
use App\Handlers\Formulas\Abstracts\AbstractFormulaFunctionHandler;
use DateTimeZone;
use Illuminate\Support\Carbon;

class TodayFunctionHandler extends AbstractFormulaFunctionHandler
{
    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Today;
    }

    /**
     * @param  list<mixed>  $arguments
     */
    protected function compute(array $arguments): mixed
    {
        return Carbon::now(new DateTimeZone((string) config('app.timezone')))->format('Y-m-d');
    }
}
