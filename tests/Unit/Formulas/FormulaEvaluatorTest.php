<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FormulaErrorValue;
use App\DTOs\Formulas\FormulaEvaluationContext;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaOperator;
use App\Exceptions\Formulas\FormulaTypeException;
use App\Support\Formulas\FormulaEvaluator;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaValueCoercer;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

class RecordingEvaluatorFunctionDouble implements FormulaFunctionHandler
{
    /**
     * @var list<list<mixed>>
     */
    public array $invocations = [];

    public mixed $result = 0;

    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed
    {
        $this->invocations[] = $arguments;

        return $this->result;
    }

    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Sum;
    }
}

beforeEach(function (): void {
    /** @var callable(string, array<string, mixed>):(string|bool|FormulaErrorValue) */
    $this->evaluate = function (string $formula, array $values = []): string|bool|FormulaErrorValue {
        return app(FormulaEvaluator::class)->evaluateExact(
            app(FormulaParser::class)->parse($formula),
            new FormulaEvaluationContext($values),
        );
    };

    /** @var callable(FormulaNode, array<string, mixed>):(string|bool|FormulaErrorValue) */
    $this->evaluateNode = function (FormulaNode $node, array $values = []): string|bool|FormulaErrorValue {
        return app(FormulaEvaluator::class)->evaluateExact($node, new FormulaEvaluationContext($values));
    };

    /** @var callable(string, array<string, mixed>):FormulaErrorValue */
    $this->errorFor = function (string $formula, array $values = []): FormulaErrorValue {
        $result = ($this->evaluate)($formula, $values);

        expect($result)->toBeInstanceOf(FormulaErrorValue::class);

        return $result;
    };

    /** @var callable(mixed):RecordingEvaluatorFunctionDouble */
    $this->registerSumDouble = function (mixed $result = 0): RecordingEvaluatorFunctionDouble {
        $double = new RecordingEvaluatorFunctionDouble;
        $double->result = $result;

        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => RecordingEvaluatorFunctionDouble::class,
        ]]);

        app()->instance(RecordingEvaluatorFunctionDouble::class, $double);

        return $double;
    };
});

describe('Formula evaluation happy path', function (): void {
    test('a percentage of a numeric field is returned as an exact decimal string', function (): void {
        expect(($this->evaluate)('{amount} * 0,19', ['amount' => 100]))->toBe('19');
    });

    test('decimal arithmetic is exact instead of binary', function (): void {
        expect(($this->evaluate)('0,1 + 0,2'))->toBe('0.3');
    });

    test('subtraction chains associate to the left', function (): void {
        expect(($this->evaluate)('10 - 3 - 2'))->toBe('5');
    });

    test('a unary minus negates its operand', function (string $formula, array $values, string $expected): void {
        expect(($this->evaluate)($formula, $values))->toBe($expected);
    })->with([
        'a negated literal inside a sum' => ['-5 + 8', [], '3'],
        'a negated field reference' => ['-{amount}', ['amount' => 7], '-7'],
        'a negated group' => ['-(2 * 3)', [], '-6'],
        'a negated decimal' => ['-0,5 + 1', [], '0.5'],
    ]);

    test('a comparison yields a boolean', function (string $formula, array $values, bool $expected): void {
        expect(($this->evaluate)($formula, $values))->toBe($expected);
    })->with([
        'a field above its threshold' => ['{amount} > 100', ['amount' => 150], true],
        'a field below its threshold' => ['{amount} > 100', ['amount' => 50], false],
        'a decimal equality that binary floats would fail' => ['0,1 + 0,2 = 0,3', [], true],
        'a decimal inequality' => ['0,1 + 0,2 <> 0,3', [], false],
        'a boundary that is not strictly greater' => ['{amount} >= 100', ['amount' => 100], true],
        'two equal booleans' => ['TRUE = TRUE', [], true],
        'two different booleans' => ['TRUE <> FALSE', [], true],
        'a boolean field against a literal' => ['{flag} = TRUE', ['flag' => true], true],
        'two equal texts' => ['"a" = "a"', [], true],
        'two ordered texts' => ['"a" < "b"', [], true],
    ]);

    test('the same number reaches the arithmetic no matter how the context stores it', function (mixed $stored): void {
        expect(($this->evaluate)('{amount} * 2', ['amount' => $stored]))->toBe('200');
    })->with([
        'a php integer' => [100],
        'a php float' => [100.0],
        'a numeric string' => ['100'],
        'a numeric string with decimals' => ['100.00'],
    ]);

    test('a float context value beyond the integer range is computed instead of rejected', function (): void {
        expect(($this->evaluate)('{amount} + 0', ['amount' => 1.0E+20]))->toBe('100000000000000000000');
    });

    test('a fractional float context value keeps its decimal precision', function (): void {
        expect(($this->evaluate)('{amount} + 0,2', ['amount' => 0.1]))->toBe('0.3');
    });

    test('zero and negative zero normalize to a plain zero', function (string $formula, array $values): void {
        expect(($this->evaluate)($formula, $values))->toBe('0');
    })->with([
        'a zero integer field' => ['{amount}', ['amount' => 0]],
        'a negative zero string field' => ['{amount}', ['amount' => '-0']],
        'a negative float below the working scale' => ['{amount}', ['amount' => -0.00000000001]],
        'a zero numerator' => ['0 / 5', []],
        'a difference that cancels out' => ['0,3 - 0,3', []],
    ]);

    test('a text value that is not numeric is returned unchanged', function (): void {
        expect(($this->evaluate)('{label}', ['label' => 'abc']))->toBe('abc');
    });

    test('a boolean literal is returned as a boolean', function (): void {
        expect(($this->evaluate)('TRUE'))->toBe(true)
            ->and(($this->evaluate)('FALSE'))->toBe(false);
    });
});

describe('Function handler dispatch', function (): void {
    test('a handler receives the already evaluated arguments in tree order', function (): void {
        $double = ($this->registerSumDouble)(6);

        expect(($this->evaluate)('SUM({first}; 2; {second})', ['first' => 1, 'second' => 3]))->toBe('6')
            ->and($double->invocations)->toHaveCount(1);

        $arguments = $double->invocations[0];

        expect($arguments)->toHaveCount(3);

        foreach ($arguments as $argument) {
            expect($argument)->toBeScalar();
        }

        expect(array_map(static fn (mixed $argument): float => (float) $argument, $arguments))
            ->toBe([1.0, 2.0, 3.0]);
    });

    test('a handler return value is normalized like any other operand', function (mixed $returned, mixed $expected): void {
        ($this->registerSumDouble)($returned);

        expect(($this->evaluate)('SUM(1)'))->toBe($expected);
    })->with([
        'a binary float that must be rounded to the working scale' => [0.1 + 0.2, '0.3'],
        'an integer' => [19, '19'],
        'a numeric string with trailing zeros' => ['0.30', '0.3'],
        'a text value' => ['Nr. A-7', 'Nr. A-7'],
        'a boolean' => [true, true],
    ]);

    test('an error value returned by a handler is propagated unchanged', function (): void {
        ($this->registerSumDouble)(new FormulaErrorValue(FormulaErrorCode::InvalidDate, 'start_date'));

        $result = ($this->errorFor)('SUM(1)');

        expect($result->code)->toBe(FormulaErrorCode::InvalidDate)
            ->and($result->fieldKey)->toBe('start_date');
    });

    test('a handler value that has no representation becomes a type mismatch', function (mixed $returned): void {
        ($this->registerSumDouble)($returned);

        expect(($this->errorFor)('SUM(1)')->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a null value' => [null],
        'an array' => [[1, 2]],
        'an object' => [new stdClass],
    ]);
});

describe('Runtime failures are named values instead of exceptions', function (): void {
    test('dividing by zero yields a named error without throwing', function (): void {
        $thrown = null;
        $result = null;

        try {
            $result = ($this->evaluate)('1 / 0');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeNull()
            ->and($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe(FormulaErrorCode::DivisionByZero);
    });

    test('dividing by a field that holds zero yields the same error', function (): void {
        expect(($this->errorFor)('{amount} / {divisor}', ['amount' => 10, 'divisor' => 0])->code)
            ->toBe(FormulaErrorCode::DivisionByZero);
    });

    test('a divisor below the working precision still divides', function (): void {
        expect(($this->evaluate)('1 / 0,00000000001'))->toBe('100000000000');
    });

    test('an unknown field reference names the missing key', function (): void {
        $result = ($this->errorFor)('{missing_field} + 1');

        expect($result->code)->toBe(FormulaErrorCode::UnknownFieldReference)
            ->and($result->fieldKey)->toBe('missing_field');
    });

    test('an empty field is not silently treated as zero', function (): void {
        $result = ($this->errorFor)('{empty_field} + 1', ['empty_field' => null]);

        expect($result->code)->toBe(FormulaErrorCode::NotANumber)
            ->and($result->fieldKey)->toBe('empty_field');
    });

    test('a field value that is not fully numeric is a type mismatch', function (mixed $stored): void {
        expect(($this->errorFor)('{value} * 2', ['value' => $stored])->code)
            ->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a word' => ['abc'],
        'an exponent notation string' => ['1e3'],
        'a leading space' => [' 5'],
        'an empty string' => [''],
        'a hexadecimal string' => ['0x1A'],
        'a boolean' => [true],
    ]);

    test('a float that is not finite is not a number', function (mixed $stored): void {
        expect(($this->errorFor)('{value} + 1', ['value' => $stored])->code)
            ->toBe(FormulaErrorCode::NotANumber);
    })->with([
        'positive infinity' => [INF],
        'negative infinity' => [-INF],
        'not a number' => [NAN],
    ]);

    test('a conditional built with the wrong argument count is refused', function (int $argumentCount): void {
        $arguments = [new BooleanLiteralNode(true, 3)];

        for ($index = 1; $index < $argumentCount; $index++) {
            $arguments[] = new NumberLiteralNode((string) $index, 3 + $index);
        }

        $result = ($this->evaluateNode)(new FunctionCallNode(FormulaFunction::IfThenElse, $arguments, 0));

        expect($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe(FormulaErrorCode::InvalidArgumentCount);
    })->with([
        'two arguments' => [2],
        'one argument' => [1],
        'four arguments' => [4],
    ]);

    test('a conditional whose condition is not boolean is a type mismatch', function (string $formula): void {
        expect(($this->errorFor)($formula)->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a numeric condition' => ['IF(1; "a"; "b")'],
        'a text condition' => ['IF("yes"; "a"; "b")'],
    ]);

    test('ordering two booleans is a type mismatch', function (string $formula): void {
        expect(($this->errorFor)($formula)->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'greater than' => ['TRUE > FALSE'],
        'less than or equal' => ['TRUE <= FALSE'],
    ]);

    test('comparing a boolean against another type is a type mismatch', function (
        string $formula,
        array $values,
    ): void {
        expect(($this->errorFor)($formula, $values)->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a boolean field on the left of a text' => ['{flag} = "a"', ['flag' => true]],
        'a boolean field on the right of a text' => ['"a" = {flag}', ['flag' => true]],
        'a boolean field against a number' => ['{flag} = 1', ['flag' => true]],
        'a boolean literal against a text' => ['TRUE <> "a"', []],
        'a boolean field ordered against a number' => ['{flag} > 1', ['flag' => false]],
    ]);

    test('a unary operator other than minus is a type mismatch', function (FormulaOperator $operator): void {
        $result = ($this->evaluateNode)(
            new UnaryOperationNode($operator, new NumberLiteralNode('1', 1), 0),
        );

        expect($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a unary plus' => [FormulaOperator::Plus],
        'a unary asterisk' => [FormulaOperator::Asterisk],
        'a unary comparison operator' => [FormulaOperator::GreaterThan],
    ]);

    test('exceeding the evaluation budget yields a named error instead of aborting', function (): void {
        expect(($this->evaluate)('1 + 2 + 3 + 4'))->toBe('10');

        config(['formulas.max_evaluation_steps' => 3]);

        $thrown = null;
        $result = null;

        try {
            $result = ($this->evaluate)('1 + 2 + 3 + 4');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeNull()
            ->and($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe(FormulaErrorCode::EvaluationLimitExceeded);
    });

    test('the evaluation limit is a distinct named cause', function (): void {
        expect(FormulaErrorCode::EvaluationLimitExceeded->value)->toBe('evaluation_limit_exceeded')
            ->and(trim(FormulaErrorCode::EvaluationLimitExceeded->label()))->not->toBe('');

        $labels = array_map(
            static fn (FormulaErrorCode $code): string => $code->label(),
            FormulaErrorCode::cases(),
        );

        expect($labels)->toHaveCount(count(array_unique($labels)));
    });
});

describe('Error propagation and short circuit', function (): void {
    test('an error propagates through arithmetic from either operand position', function (string $formula): void {
        expect(($this->errorFor)($formula)->code)->toBe(FormulaErrorCode::DivisionByZero);
    })->with([
        'a failing left operand of an addition' => ['(1/0) + 5'],
        'a failing right operand of an addition' => ['5 + (1/0)'],
        'a failing left operand of a multiplication' => ['(1/0) * 2'],
        'a failing right operand of a subtraction' => ['2 - (1/0)'],
        'a failure nested two levels deep' => ['((1/0) + 1) * 3'],
        'a negated failure' => ['-(1/0)'],
    ]);

    test('the first error encountered wins', function (): void {
        expect(($this->errorFor)('(1/0) + {missing_field}')->code)->toBe(FormulaErrorCode::DivisionByZero);

        $reversed = ($this->errorFor)('{missing_field} + (1/0)');

        expect($reversed->code)->toBe(FormulaErrorCode::UnknownFieldReference)
            ->and($reversed->fieldKey)->toBe('missing_field');
    });

    test('an error propagates through a comparison', function (string $formula): void {
        expect(($this->errorFor)($formula)->code)->toBe(FormulaErrorCode::DivisionByZero);
    })->with([
        'a failing left operand' => ['(1/0) > 1'],
        'a failing right operand' => ['1 < (1/0)'],
        'a failing operand of an equality' => ['(1/0) = 1'],
    ]);

    test('a failing argument short circuits before the handler runs', function (): void {
        $double = ($this->registerSumDouble)(99);

        expect(($this->errorFor)('SUM({first}; 1/0)', ['first' => 1])->code)
            ->toBe(FormulaErrorCode::DivisionByZero)
            ->and($double->invocations)->toBe([]);
    });

    test('a conditional evaluates only the chosen branch', function (string $formula, mixed $expected): void {
        expect(($this->evaluate)($formula))->toBe($expected);
    })->with([
        'a true condition skips the else branch' => ['IF(TRUE; 1; 1/0)', '1'],
        'a false condition skips the then branch' => ['IF(FALSE; 1/0; 2)', '2'],
        'a computed true condition skips the else branch' => ['IF(2 > 1; "high"; 1/0)', 'high'],
    ]);

    test('the branch that is not chosen never reaches the handler', function (
        string $formula,
        mixed $expected,
        int $expectedCalls,
    ): void {
        $double = ($this->registerSumDouble)(7);

        expect(($this->evaluate)($formula))->toBe($expected)
            ->and($double->invocations)->toHaveCount($expectedCalls);
    })->with([
        'the then branch is taken' => ['IF(TRUE; SUM(1); 2)', '7', 1],
        'the then branch is skipped' => ['IF(FALSE; SUM(1); 2)', '2', 0],
        'the else branch is taken' => ['IF(FALSE; 2; SUM(1))', '7', 1],
        'the else branch is skipped' => ['IF(TRUE; 1; SUM(1))', '1', 0],
    ]);

    test('the condition of a conditional is evaluated', function (): void {
        expect(($this->errorFor)('IF(1/0; "a"; "b")')->code)->toBe(FormulaErrorCode::DivisionByZero);
    });
});

describe('The throwing boundary', function (): void {
    test('an unregistered function handler is a deployment defect and throws', function (): void {
        config(['formulas.function_handlers' => []]);

        expect(fn (): mixed => ($this->evaluate)('SUM(1)'))->toThrow(FormulaTypeException::class);
    });

    test('a conditional never consults the handler registry', function (): void {
        config(['formulas.function_handlers' => []]);

        expect(($this->evaluate)('IF(TRUE; 1; 2)'))->toBe('1');
    });

    test('a node type the evaluator does not know throws', function (): void {
        $node = new class implements FormulaNode {};

        expect(fn (): mixed => ($this->evaluateNode)($node))->toThrow(InvalidArgumentException::class);
    });
});

describe('The evaluation context', function (): void {
    test('a missing key and a stored null are different facts', function (): void {
        $context = new FormulaEvaluationContext(['empty_field' => null, 'amount' => 5]);

        expect($context->hasField('empty_field'))->toBeTrue()
            ->and($context->valueFor('empty_field'))->toBeNull()
            ->and($context->hasField('missing_field'))->toBeFalse()
            ->and($context->hasField('amount'))->toBeTrue()
            ->and($context->valueFor('amount'))->toBe(5);
    });

    test('a value of any shape survives the context unchanged', function (mixed $stored): void {
        $context = new FormulaEvaluationContext(['value' => $stored]);

        expect($context->valueFor('value'))->toBe($stored);
    })->with([
        'a float' => [0.1],
        'a boolean' => [false],
        'an empty string' => [''],
        'a zero' => [0],
    ]);
});

describe('Value coercion', function (): void {
    test('a date is accepted only in the iso format', function (mixed $input, ?string $expected): void {
        $result = app(FormulaValueCoercer::class)->toDate($input);

        if ($expected === null) {
            expect($result)->toBeInstanceOf(FormulaErrorValue::class)
                ->and($result->code)->toBe(FormulaErrorCode::InvalidDate);

            return;
        }

        expect($result)->toBe($expected);
    })->with([
        'an iso date' => ['2026-01-31', '2026-01-31'],
        'a german date' => ['31.01.2026', null],
        'a word' => ['nodate', null],
        'an empty string' => ['', null],
        'an impossible day' => ['2026-02-30', null],
        'a number' => [7, null],
    ]);

    test('a number rendered as text drops its trailing zeros', function (mixed $input, string $expected): void {
        expect(app(FormulaValueCoercer::class)->toText($input, 10))->toBe($expected);
    })->with([
        'a fraction' => [0.3, '0.3'],
        'an integer' => [19, '19'],
        'a numeric string' => ['2.50', '2.5'],
        'a text value' => ['abc', 'abc'],
    ]);

    test('only a real boolean is accepted as a boolean', function (): void {
        $coercer = app(FormulaValueCoercer::class);

        expect($coercer->toBoolean(true))->toBe(true)
            ->and($coercer->toBoolean(false))->toBe(false);

        foreach (['yes', '1', 1, 0, null, ''] as $input) {
            $result = $coercer->toBoolean($input);

            expect($result)->toBeInstanceOf(FormulaErrorValue::class)
                ->and($result->code)->toBe(FormulaErrorCode::TypeMismatch);
        }
    });

    test('a numeric value becomes a decimal string of the working scale', function (mixed $input, string $comparand): void {
        $result = app(FormulaValueCoercer::class)->toDecimalString($input, 10);

        expect($result)->toBeString()
            ->and(bccomp($result, $comparand, 10))->toBe(0);
    })->with([
        'a float' => [0.1, '0.1'],
        'an integer' => [100, '100'],
        'a numeric string' => ['2.50', '2.5'],
        'a negative float' => [-0.25, '-0.25'],
    ]);

    test('a value that is not fully numeric is refused as a decimal string', function (mixed $input): void {
        $result = app(FormulaValueCoercer::class)->toDecimalString($input, 10);

        expect($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe(FormulaErrorCode::TypeMismatch);
    })->with([
        'a word' => ['abc'],
        'an exponent notation string' => ['1e3'],
        'a leading space' => [' 5'],
        'an empty string' => [''],
        'a hexadecimal string' => ['0x1A'],
        'a boolean' => [true],
    ]);

    test('the working representation is reduced to an exact value without losing its type', function (): void {
        $coercer = app(FormulaValueCoercer::class);

        expect($coercer->toWorkingValue(true, 10))->toBe(true)
            ->and($coercer->toExactValue(true))->toBe(true)
            ->and($coercer->toExactValue(false))->toBe(false)
            ->and($coercer->toExactValue('19.0000000000'))->toBe('19')
            ->and($coercer->toExactValue('0.3000000000'))->toBe('0.3')
            ->and($coercer->toExactValue('abc'))->toBe('abc');

        $error = new FormulaErrorValue(FormulaErrorCode::DivisionByZero);

        expect($coercer->toExactValue($error))->toBe($error);
    });

    test('a string that is not a plain decimal is never trimmed', function (string $input): void {
        expect(app(FormulaValueCoercer::class)->toExactValue($input))->toBe($input);
    })->with([
        'an exponent notation string ending in a zero' => ['1.0e10'],
        'an exponent notation string without a fraction' => ['1e5'],
        'a leading space before a trailing zero' => [' 5.10'],
        'a trailing space after a trailing zero' => ['5.10 '],
        'a hexadecimal string' => ['0x1A'],
        'a version-like text' => ['v1.10'],
        'a text with a decimal suffix' => ['Nr. 7.100'],
    ]);

    test('negative zero is normalized wherever a decimal is rendered', function (): void {
        $coercer = app(FormulaValueCoercer::class);

        expect($coercer->toExactValue('-0'))->toBe('0')
            ->and($coercer->toExactValue('-0.0000000000'))->toBe('0')
            ->and($coercer->toText(-0.00000000001, 10))->toBe('0')
            ->and($coercer->toExactValue('-0.5000000000'))->toBe('-0.5');
    });
});
