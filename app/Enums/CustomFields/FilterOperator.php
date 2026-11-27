<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

enum FilterOperator: string
{
    case Equals = 'equals';

    case NotEqual = 'notEqual';

    case Contains = 'contains';

    case NotContains = 'notContains';

    case StartsWith = 'startsWith';

    case EndsWith = 'endsWith';

    case GreaterThan = 'greaterThan';

    case GreaterThanOrEqual = 'greaterThanOrEqual';

    case LessThan = 'lessThan';

    case LessThanOrEqual = 'lessThanOrEqual';

    case InRange = 'inRange';

    case Blank = 'blank';

    case NotBlank = 'notBlank';

    case In = 'in';

    case NotIn = 'notIn';

    case Has = 'has';

    case HasNot = 'hasNot';

    /**
     * @return list<self>
     */
    public static function forText(): array
    {
        return [
            self::Equals,
            self::NotEqual,
            self::Contains,
            self::NotContains,
            self::StartsWith,
            self::EndsWith,
            self::In,
            self::NotIn,
            self::Blank,
            self::NotBlank,
        ];
    }

    /**
     * @return list<self>
     */
    public static function forNumber(): array
    {
        return [
            self::Equals,
            self::NotEqual,
            self::GreaterThan,
            self::GreaterThanOrEqual,
            self::LessThan,
            self::LessThanOrEqual,
            self::InRange,
            self::Blank,
            self::NotBlank,
        ];
    }

    /**
     * @return list<self>
     */
    public static function forDate(): array
    {
        return [
            self::Equals,
            self::NotEqual,
            self::GreaterThan,
            self::LessThan,
            self::InRange,
            self::Blank,
            self::NotBlank,
        ];
    }

    /**
     * @return list<self>
     */
    public static function forChoice(): array
    {
        return [
            self::In,
            self::NotIn,
            self::Blank,
            self::NotBlank,
        ];
    }

    /**
     * @return list<self>
     */
    public static function forBoolean(): array
    {
        return [
            self::Equals,
            self::Blank,
        ];
    }

    /**
     * @return list<self>
     */
    public static function forPresence(): array
    {
        return [
            self::Blank,
            self::NotBlank,
        ];
    }
}
