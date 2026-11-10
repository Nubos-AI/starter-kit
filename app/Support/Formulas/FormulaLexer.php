<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\DTOs\Formulas\FormulaToken;
use App\Enums\Formulas\TokenType;
use App\Exceptions\Formulas\FormulaSyntaxException;

class FormulaLexer
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    private string $whitespaceCharacters = " \t\r\n";

    private string $decimalSeparators = '.,';

    /**
     * @return list<FormulaToken>
     *
     * @throws FormulaSyntaxException
     */
    public function tokenize(string $formula): array
    {
        $length = mb_strlen($formula);
        $maximum = (int) config('formulas.max_formula_length');

        if ($length > $maximum) {
            throw FormulaSyntaxException::tooLong($length, $maximum);
        }

        $characters = mb_str_split($formula);
        $tokens = [];
        $cursor = 0;

        while ($cursor < $length) {
            $character = $characters[$cursor];

            if ($this->isWhitespace($character)) {
                $cursor++;

                continue;
            }

            $tokens[] = match (true) {
                $character === '{' => $this->readFieldReference($characters, $cursor),
                $character === '"' => $this->readText($characters, $cursor),
                ctype_digit($character) => $this->readNumber($characters, $cursor),
                ctype_alpha($character) || $character === '_' => $this->readWord($characters, $cursor),
                default => $this->readOperator($characters, $cursor),
            };
        }

        $tokens[] = new FormulaToken(TokenType::EndOfInput, '', $length);

        return $tokens;
    }

    /**
     * @param  list<string>  $characters
     *
     * @throws FormulaSyntaxException
     */
    private function readFieldReference(array $characters, int &$cursor): FormulaToken
    {
        $start = $cursor;
        $length = count($characters);

        if (($characters[$start + 1] ?? '') === '{') {
            throw FormulaSyntaxException::doubleBrace($start);
        }

        $end = $start + 1;

        while ($end < $length && $characters[$end] !== '}') {
            $end++;
        }

        if ($end === $length) {
            throw FormulaSyntaxException::unterminatedFieldReference($start);
        }

        $key = implode('', array_slice($characters, $start + 1, $end - $start - 1));

        if (preg_match($this->keyPattern, $key) !== 1) {
            throw FormulaSyntaxException::invalidFieldKey($key, $start);
        }

        $cursor = $end + 1;

        return new FormulaToken(TokenType::FieldReference, $key, $start);
    }

    /**
     * @param  list<string>  $characters
     *
     * @throws FormulaSyntaxException
     */
    private function readText(array $characters, int &$cursor): FormulaToken
    {
        $start = $cursor;
        $length = count($characters);
        $value = '';
        $scan = $start + 1;

        while ($scan < $length) {
            $character = $characters[$scan];

            if ($character === '"') {
                $cursor = $scan + 1;

                return new FormulaToken(TokenType::Text, $value, $start);
            }

            if ($character === '\\' && ($characters[$scan + 1] ?? '') === '"') {
                $value .= '"';
                $scan += 2;

                continue;
            }

            $value .= $character;
            $scan++;
        }

        throw FormulaSyntaxException::unterminatedText($start);
    }

    /**
     * @param  list<string>  $characters
     *
     * @throws FormulaSyntaxException
     */
    private function readNumber(array $characters, int &$cursor): FormulaToken
    {
        $start = $cursor;
        $length = count($characters);
        $digits = '';
        $separatorPosition = null;

        while ($cursor < $length) {
            $character = $characters[$cursor];

            if (ctype_digit($character)) {
                $digits .= $character;
                $cursor++;

                continue;
            }

            if (!str_contains($this->decimalSeparators, $character)) {
                break;
            }

            if ($separatorPosition !== null) {
                throw FormulaSyntaxException::duplicateDecimalSeparator($cursor);
            }

            $separatorPosition = $cursor;
            $digits .= '.';
            $cursor++;
        }

        if ($separatorPosition === $cursor - 1) {
            throw FormulaSyntaxException::trailingDecimalSeparator($cursor - 1);
        }

        $this->rejectDigitGroupSeparator($characters, $cursor);

        return new FormulaToken(TokenType::Number, $digits, $start);
    }

    /**
     * @param  list<string>  $characters
     */
    private function readWord(array $characters, int &$cursor): FormulaToken
    {
        $start = $cursor;
        $length = count($characters);
        $word = '';

        while ($cursor < $length && (ctype_alnum($characters[$cursor]) || $characters[$cursor] === '_')) {
            $word .= $characters[$cursor];
            $cursor++;
        }

        $type = match ($word) {
            'TRUE', 'FALSE' => TokenType::Boolean,
            default => TokenType::Identifier,
        };

        return new FormulaToken($type, $word, $start);
    }

    /**
     * @param  list<string>  $characters
     *
     * @throws FormulaSyntaxException
     */
    private function readOperator(array $characters, int &$cursor): FormulaToken
    {
        $start = $cursor;
        $character = $characters[$start];
        $next = $characters[$start + 1] ?? '';

        $token = match (true) {
            $character === '<' && $next === '>' => new FormulaToken(TokenType::NotEqual, '<>', $start),
            $character === '<' && $next === '=' => new FormulaToken(TokenType::LessThanOrEqual, '<=', $start),
            $character === '>' && $next === '=' => new FormulaToken(TokenType::GreaterThanOrEqual, '>=', $start),
            default => new FormulaToken($this->singleCharacterType($character, $start), $character, $start),
        };

        $cursor = $start + mb_strlen($token->text);

        return $token;
    }

    /**
     * @throws FormulaSyntaxException
     */
    private function singleCharacterType(string $character, int $position): TokenType
    {
        return match ($character) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Asterisk,
            '/' => TokenType::Slash,
            '&' => TokenType::Ampersand,
            '=' => TokenType::Equal,
            '<' => TokenType::LessThan,
            '>' => TokenType::GreaterThan,
            '(' => TokenType::LeftParenthesis,
            ')' => TokenType::RightParenthesis,
            ';' => TokenType::Semicolon,
            default => throw FormulaSyntaxException::unexpectedCharacter($character, $position),
        };
    }

    /**
     * @param  list<string>  $characters
     *
     * @throws FormulaSyntaxException
     */
    private function rejectDigitGroupSeparator(array $characters, int $cursor): void
    {
        $length = count($characters);
        $peek = $cursor;

        while ($peek < $length && $this->isWhitespace($characters[$peek])) {
            $peek++;
        }

        if ($peek < $length && ctype_digit($characters[$peek])) {
            throw FormulaSyntaxException::digitGroupSeparator($peek);
        }
    }

    private function isWhitespace(string $character): bool
    {
        return str_contains($this->whitespaceCharacters, $character);
    }
}
