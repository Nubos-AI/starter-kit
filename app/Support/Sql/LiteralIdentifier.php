<?php

declare(strict_types=1);

namespace App\Support\Sql;

use InvalidArgumentException;

class LiteralIdentifier
{
    private string $lowerSnake = '[a-z0-9_]';

    private string $mixedAlnum = '[A-Za-z0-9_]';

    private string $timeZone = '[A-Za-z0-9_/+-]';

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    public function lowerSnake(string $value): string
    {
        $result = '';

        foreach ($this->bytes($value, $this->lowerSnake) as $char) {
            $result .= $this->lowerLetter($char)
                ?? $this->digit($char)
                ?? $this->underscore($char)
                ?? throw $this->rejected($value, $this->lowerSnake);
        }

        return $result;
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    public function mixedAlnum(string $value): string
    {
        $result = '';

        foreach ($this->bytes($value, $this->mixedAlnum) as $char) {
            $result .= $this->lowerLetter($char)
                ?? $this->upperLetter($char)
                ?? $this->digit($char)
                ?? $this->underscore($char)
                ?? throw $this->rejected($value, $this->mixedAlnum);
        }

        return $result;
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    public function timeZone(string $value): string
    {
        $result = '';

        foreach ($this->bytes($value, $this->timeZone) as $char) {
            $result .= $this->lowerLetter($char)
                ?? $this->upperLetter($char)
                ?? $this->digit($char)
                ?? $this->underscore($char)
                ?? $this->zoneSeparator($char)
                ?? throw $this->rejected($value, $this->timeZone);
        }

        return $result;
    }

    /**
     * @return literal-string|null
     */
    private function lowerLetter(string $char): ?string
    {
        return match ($char) {
            'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e', 'f' => 'f',
            'g' => 'g', 'h' => 'h', 'i' => 'i', 'j' => 'j', 'k' => 'k', 'l' => 'l',
            'm' => 'm', 'n' => 'n', 'o' => 'o', 'p' => 'p', 'q' => 'q', 'r' => 'r',
            's' => 's', 't' => 't', 'u' => 'u', 'v' => 'v', 'w' => 'w', 'x' => 'x',
            'y' => 'y', 'z' => 'z',
            default => null,
        };
    }

    /**
     * @return literal-string|null
     */
    private function upperLetter(string $char): ?string
    {
        return match ($char) {
            'A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', 'F' => 'F',
            'G' => 'G', 'H' => 'H', 'I' => 'I', 'J' => 'J', 'K' => 'K', 'L' => 'L',
            'M' => 'M', 'N' => 'N', 'O' => 'O', 'P' => 'P', 'Q' => 'Q', 'R' => 'R',
            'S' => 'S', 'T' => 'T', 'U' => 'U', 'V' => 'V', 'W' => 'W', 'X' => 'X',
            'Y' => 'Y', 'Z' => 'Z',
            default => null,
        };
    }

    /**
     * @return literal-string|null
     */
    private function digit(string $char): ?string
    {
        return match ($char) {
            '0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5',
            '6' => '6', '7' => '7', '8' => '8', '9' => '9',
            default => null,
        };
    }

    /**
     * @return literal-string|null
     */
    private function underscore(string $char): ?string
    {
        return $char === '_' ? '_' : null;
    }

    /**
     * @return literal-string|null
     */
    private function zoneSeparator(string $char): ?string
    {
        return match ($char) {
            '/' => '/', '+' => '+', '-' => '-',
            default => null,
        };
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function bytes(string $value, string $allowlist): array
    {
        if ($value === '') {
            throw $this->rejected($value, $allowlist);
        }

        return str_split($value);
    }

    private function rejected(string $value, string $allowlist): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Identifier \"{$value}\" contains a byte outside the {$allowlist} allowlist.",
        );
    }
}
