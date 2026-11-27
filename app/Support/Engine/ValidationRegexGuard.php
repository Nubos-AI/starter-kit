<?php

declare(strict_types=1);

namespace App\Support\Engine;

use Closure;

class ValidationRegexGuard
{
    /**
     * @var array<string, string>
     */
    private array $bracketDelimiters = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];

    public function isUsable(string $pattern): bool
    {
        if ($pattern === '' || strlen($pattern) > (int) config('engine.validation.max_regex_length')) {
            return false;
        }

        if (@preg_match($pattern, '') === false) {
            return false;
        }

        return !$this->hasRepeatedAmbiguousGroup($this->bodyOf($pattern));
    }

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $pattern = is_array($value) ? ($value['regex'] ?? null) : null;

            if (is_string($pattern) && $pattern !== '' && !$this->isUsable($pattern)) {
                $fail(__('i18n.backend.support.engine.validation_regex_guard.the_pattern_is_invalid_or_too_expensive'));
            }
        };
    }

    private function bodyOf(string $pattern): string
    {
        $opening = $pattern[0];
        $closing = $this->bracketDelimiters[$opening] ?? $opening;
        $end = strrpos($pattern, $closing);

        return $end === false || $end === 0 ? '' : substr($pattern, 1, $end - 1);
    }

    private function hasRepeatedAmbiguousGroup(string $body): bool
    {
        $length = strlen($body);
        $enclosing = [];
        $isAmbiguous = false;
        $position = 0;

        while ($position < $length) {
            $char = $body[$position];

            if ($char === '(') {
                $enclosing[] = $isAmbiguous;
                $isAmbiguous = false;
                $position += ($body[$position + 1] ?? '') === '?' ? 2 : 1;

                continue;
            }

            if ($char === ')') {
                $groupIsAmbiguous = $isAmbiguous;
                $isAmbiguous = array_pop($enclosing) ?? false;
                [$isQuantified, $repeats, $position] = $this->quantifierAt($body, $position + 1);

                if ($repeats && $groupIsAmbiguous) {
                    return true;
                }

                $isAmbiguous = $isAmbiguous || $groupIsAmbiguous || $isQuantified;

                continue;
            }

            if ($char === '|') {
                $isAmbiguous = true;
                $position++;

                continue;
            }

            [$isQuantified, , $position] = $this->quantifierAt($body, $this->afterAtom($body, $position));
            $isAmbiguous = $isAmbiguous || $isQuantified;
        }

        return false;
    }

    private function afterAtom(string $body, int $position): int
    {
        if ($body[$position] === '\\') {
            return $position + 2;
        }

        if ($body[$position] !== '[') {
            return $position + 1;
        }

        $length = strlen($body);
        $cursor = $position + 1;

        if (($body[$cursor] ?? '') === '^') {
            $cursor++;
        }

        if (($body[$cursor] ?? '') === ']') {
            $cursor++;
        }

        while ($cursor < $length && $body[$cursor] !== ']') {
            $cursor += $body[$cursor] === '\\' ? 2 : 1;
        }

        return $cursor + 1;
    }

    /**
     * @return array{0: bool, 1: bool, 2: int}
     */
    private function quantifierAt(string $body, int $position): array
    {
        $char = $body[$position] ?? '';

        if ($char === '*' || $char === '+') {
            return [true, true, $this->afterQuantifierModifier($body, $position + 1)];
        }

        if ($char === '?') {
            return [true, false, $this->afterQuantifierModifier($body, $position + 1)];
        }

        if ($char === '{' && preg_match('/\G\{(\d+)(,(\d*))?\}/', $body, $matches, 0, $position) === 1) {
            $repeats = isset($matches[2])
                ? ($matches[3] ?? '') === '' || (int) $matches[3] > 1
                : (int) $matches[1] > 1;

            return [true, $repeats, $this->afterQuantifierModifier($body, $position + strlen($matches[0]))];
        }

        return [false, false, $position];
    }

    private function afterQuantifierModifier(string $body, int $position): int
    {
        $char = $body[$position] ?? '';

        return $char === '?' || $char === '+' ? $position + 1 : $position;
    }
}
