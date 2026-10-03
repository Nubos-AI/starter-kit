<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\Enums\Formulas\FormulaOperator;

readonly class BinaryOperationNode implements FormulaNode
{
    public function __construct(
        public FormulaOperator $operator,
        public FormulaNode $left,
        public FormulaNode $right,
        public int $position,
    ) {}
}
