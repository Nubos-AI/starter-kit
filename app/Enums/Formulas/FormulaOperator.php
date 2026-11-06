<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

enum FormulaOperator: string
{
    case Equal = '=';

    case NotEqual = '<>';

    case LessThan = '<';

    case LessThanOrEqual = '<=';

    case GreaterThan = '>';

    case GreaterThanOrEqual = '>=';

    case Plus = '+';

    case Minus = '-';

    case Asterisk = '*';

    case Slash = '/';

    case Ampersand = '&';

    public static function fromTokenType(TokenType $type): ?self
    {
        return match ($type) {
            TokenType::Equal => self::Equal,
            TokenType::NotEqual => self::NotEqual,
            TokenType::LessThan => self::LessThan,
            TokenType::LessThanOrEqual => self::LessThanOrEqual,
            TokenType::GreaterThan => self::GreaterThan,
            TokenType::GreaterThanOrEqual => self::GreaterThanOrEqual,
            TokenType::Plus => self::Plus,
            TokenType::Minus => self::Minus,
            TokenType::Asterisk => self::Asterisk,
            TokenType::Slash => self::Slash,
            TokenType::Ampersand => self::Ampersand,
            default => null,
        };
    }

    public function precedence(): int
    {
        return match ($this) {
            self::Equal, self::NotEqual => 1,
            self::LessThan, self::LessThanOrEqual, self::GreaterThan, self::GreaterThanOrEqual => 2,
            self::Ampersand => 3,
            self::Plus, self::Minus => 4,
            self::Asterisk, self::Slash => 5,
        };
    }

    public function isLeftAssociative(): bool
    {
        return match ($this) {
            self::Equal,
            self::NotEqual,
            self::LessThan,
            self::LessThanOrEqual,
            self::GreaterThan,
            self::GreaterThanOrEqual,
            self::Plus,
            self::Minus,
            self::Asterisk,
            self::Slash,
            self::Ampersand => true,
        };
    }

    public function prefixPrecedence(): ?int
    {
        return match ($this) {
            self::Minus => 6,
            default => null,
        };
    }
}
