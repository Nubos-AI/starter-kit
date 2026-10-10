<?php

declare(strict_types=1);

namespace App\Handlers\Formulas\Abstracts;

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

abstract class AbstractFormulaFunctionHandler implements FormulaFunctionHandler
{
    /**
     * @var list<string>
     */
    private array $dateFormats = ['!Y-m-d', '!Y-m-d H:i:s'];

    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed
    {
        foreach ($this->errorPropagatingArguments($arguments) as $argument) {
            if ($argument instanceof FormulaErrorValue) {
                return $argument;
            }
        }

        if (!$this->formulaFunction()->signature()->acceptsArgumentCount(count($arguments))) {
            return new FormulaErrorValue(FormulaErrorCode::InvalidArgumentCount);
        }

        return $this->compute($arguments);
    }

    abstract public function formulaFunction(): FormulaFunction;

    /**
     * @param  list<mixed>  $arguments
     */
    abstract protected function compute(array $arguments): mixed;

    /**
     * @param  list<mixed>  $arguments
     * @return list<mixed>
     */
    protected function errorPropagatingArguments(array $arguments): array
    {
        return $arguments;
    }

    /**
     * @return numeric-string|null
     */
    protected function readDecimal(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        $scale = (int) config('formulas.scale');
        $candidate = is_float($value) ? sprintf("%.{$scale}F", $value) : (string) $value;

        return is_numeric($candidate) && preg_match('/^-?\d+(\.\d+)?$/D', $candidate) === 1 ? $candidate : null;
    }

    protected function readDate(mixed $value): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            return null;
        }

        foreach ($this->dateFormats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);

            if ($parsed !== false && $parsed->format(ltrim($format, '!')) === $value) {
                return $parsed->setTime(0, 0);
            }
        }

        return null;
    }

    protected function readText(mixed $value): ?string
    {
        $decimal = $this->readDecimal($value);

        if ($decimal !== null) {
            return $this->trimDecimal($decimal);
        }

        return is_string($value) ? $value : null;
    }

    protected function trimDecimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '-0' ? '0' : $trimmed;
    }
}
