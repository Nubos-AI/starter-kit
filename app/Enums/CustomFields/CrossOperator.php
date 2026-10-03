<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

enum CrossOperator: string
{
    case After = 'after';

    case AfterOrEqual = 'after_or_equal';

    case Before = 'before';

    case BeforeOrEqual = 'before_or_equal';

    case GreaterThan = 'gt';

    case GreaterThanOrEqual = 'gte';

    case LessThan = 'lt';

    case LessThanOrEqual = 'lte';

    case Same = 'same';

    case Different = 'different';

    public function isNumeric(): bool
    {
        return match ($this) {
            self::GreaterThan, self::GreaterThanOrEqual, self::LessThan, self::LessThanOrEqual => true,
            default => false,
        };
    }
}
