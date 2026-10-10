<?php

declare(strict_types=1);

namespace App\Enums\Formulas;

use App\DTOs\Formulas\FormulaFunctionSignature;

enum FormulaFunction: string
{
    case IfThenElse = 'IF';

    case Sum = 'SUM';

    case Round = 'ROUND';

    case Concat = 'CONCAT';

    case DateDif = 'DATEDIF';

    case Today = 'TODAY';

    case Trim = 'TRIM';

    public function signature(): FormulaFunctionSignature
    {
        return match ($this) {
            self::IfThenElse => new FormulaFunctionSignature(
                minArgs: 3,
                maxArgs: 3,
                argumentTypes: [FormulaValueType::Boolean, null, null],
                resultType: null,
            ),
            self::Sum => new FormulaFunctionSignature(
                minArgs: 1,
                maxArgs: null,
                argumentTypes: [FormulaValueType::Number],
                resultType: FormulaValueType::Number,
            ),
            self::Round => new FormulaFunctionSignature(
                minArgs: 2,
                maxArgs: 2,
                argumentTypes: [FormulaValueType::Number, FormulaValueType::Number],
                resultType: FormulaValueType::Number,
            ),
            self::Concat => new FormulaFunctionSignature(
                minArgs: 1,
                maxArgs: null,
                argumentTypes: [FormulaValueType::Text],
                resultType: FormulaValueType::Text,
            ),
            self::Trim => new FormulaFunctionSignature(
                minArgs: 1,
                maxArgs: 1,
                argumentTypes: [FormulaValueType::Text],
                resultType: FormulaValueType::Text,
            ),
            self::DateDif => new FormulaFunctionSignature(
                minArgs: 3,
                maxArgs: 3,
                argumentTypes: [FormulaValueType::Date, FormulaValueType::Date, FormulaValueType::Text],
                resultType: FormulaValueType::Number,
            ),
            self::Today => new FormulaFunctionSignature(
                minArgs: 0,
                maxArgs: 0,
                argumentTypes: [],
                resultType: FormulaValueType::Date,
            ),
        };
    }
}
