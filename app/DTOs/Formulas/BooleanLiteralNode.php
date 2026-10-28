<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Contracts\Formulas\FormulaNode;

readonly class BooleanLiteralNode implements FormulaNode
{
    public function __construct(
        public bool $value,
        public int $position,
    ) {}
}
