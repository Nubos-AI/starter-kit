<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaTypeException;

readonly class FormulaFunctionSignature
{
    /**
     * @param  list<FormulaValueType|null>  $argumentTypes
     *
     * @throws FormulaTypeException
     */
    public function __construct(
        public int $minArgs,
        public ?int $maxArgs,
        public array $argumentTypes,
        public ?FormulaValueType $resultType,
    ) {
        if ($minArgs < 0) {
            throw FormulaTypeException::invalidSignature('the minimum arity must not be negative.');
        }

        if ($maxArgs !== null && $maxArgs < $minArgs) {
            throw FormulaTypeException::invalidSignature('the maximum arity must not be below the minimum arity.');
        }

        if ($maxArgs === null && $argumentTypes === []) {
            throw FormulaTypeException::invalidSignature('a variadic signature must declare at least one argument type.');
        }

        if ($maxArgs !== null && count($argumentTypes) !== $maxArgs) {
            throw FormulaTypeException::invalidSignature('a fixed arity signature must declare one argument type per accepted argument.');
        }
    }

    public function isVariadic(): bool
    {
        return $this->maxArgs === null;
    }

    public function acceptsArgumentCount(int $count): bool
    {
        return $count >= $this->minArgs && ($this->maxArgs === null || $count <= $this->maxArgs);
    }

    /**
     * @throws FormulaTypeException
     */
    public function expectedTypeAt(int $index): ?FormulaValueType
    {
        if ($index < 0) {
            throw FormulaTypeException::invalidSignature('an argument index must not be negative.');
        }

        if ($this->isVariadic()) {
            return $this->argumentTypes[min($index, count($this->argumentTypes) - 1)];
        }

        if ($index >= count($this->argumentTypes)) {
            throw FormulaTypeException::invalidSignature("no argument is accepted at index {$index}.");
        }

        return $this->argumentTypes[$index];
    }
}
