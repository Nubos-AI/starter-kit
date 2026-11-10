<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Enums\Formulas\TokenType;

readonly class FormulaToken
{
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $position,
    ) {}
}
