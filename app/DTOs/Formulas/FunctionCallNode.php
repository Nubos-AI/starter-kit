<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\Enums\Formulas\FormulaFunction;

readonly class FunctionCallNode implements FormulaNode
{
    /**
     * @param  list<FormulaNode>  $arguments
     */
    public function __construct(
        public FormulaFunction $function,
        public array $arguments,
        public int $position,
    ) {}
}
