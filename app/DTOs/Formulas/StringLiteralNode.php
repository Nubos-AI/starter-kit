<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Contracts\Formulas\FormulaNode;

readonly class StringLiteralNode implements FormulaNode
{
    public function __construct(
        public string $value,
        public int $position,
    ) {}
}
