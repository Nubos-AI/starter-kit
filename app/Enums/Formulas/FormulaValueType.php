<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

use App\Enums\CustomFields\FilterOperator;

enum FormulaValueType: string
{
    case Number = 'number';

    case Text = 'text';

    case Date = 'date';

    case Boolean = 'boolean';

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(): array
    {
        return match ($this) {
            self::Number => FilterOperator::forNumber(),
            self::Text => FilterOperator::forText(),
            self::Date => FilterOperator::forDate(),
            self::Boolean => FilterOperator::forBoolean(),
        };
    }
}
