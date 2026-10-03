<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Enums\Formulas\FormulaValueType;

readonly class FormulaEvaluationContext
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, FormulaValueType|null>  $types
     */
    public function __construct(private array $values, private array $types = []) {}

    public function hasField(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function valueFor(string $key): mixed
    {
        return $this->hasField($key) ? $this->values[$key] : null;
    }

    public function typeOf(string $key): ?FormulaValueType
    {
        return $this->types[$key] ?? null;
    }
}
