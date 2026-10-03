<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

enum TokenType
{
    case Number;

    case Text;

    case Boolean;

    case FieldReference;

    case Identifier;

    case Plus;

    case Minus;

    case Asterisk;

    case Slash;

    case Ampersand;

    case Equal;

    case NotEqual;

    case GreaterThan;

    case GreaterThanOrEqual;

    case LessThan;

    case LessThanOrEqual;

    case LeftParenthesis;

    case RightParenthesis;

    case Semicolon;

    case EndOfInput;
}
