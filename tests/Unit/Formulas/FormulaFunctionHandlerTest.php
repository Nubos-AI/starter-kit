<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Support\Formulas\FormulaFunctionRegistry;
use Illuminate\Support\Carbon;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(FormulaFunction):FormulaFunctionHandler */
    $this->handlerFor = fn (FormulaFunction $function): FormulaFunctionHandler => app(FormulaFunctionRegistry::class)
        ->handlerFor($function);

    /** @var callable(FormulaFunction, list<mixed>):mixed */
    $this->apply = fn (FormulaFunction $function, array $arguments): mixed => ($this->handlerFor)($function)
        ->evaluate($arguments);

    /** @var callable(FormulaFunction, mixed...):mixed */
    $this->evaluate = fn (FormulaFunction $function, mixed ...$arguments): mixed => ($this->apply)(
        $function,
        array_values($arguments),
    );

    /** @var callable(mixed, FormulaErrorCode):void */
    $this->expectFailure = function (mixed $result, FormulaErrorCode $code): void {
        expect($result)->toBeInstanceOf(FormulaErrorValue::class)
            ->and($result->code)->toBe($code);
    };

    /** @var callable(?string):FormulaErrorValue */
    $this->failedArgument = fn (?string $fieldKey = 'amount'): FormulaErrorValue => new FormulaErrorValue(
        FormulaErrorCode::DivisionByZero,
        $fieldKey,
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('Formula function handler registration', function (): void {
    test('every catalog function resolves to a handler that names itself', function (): void {
        $registry = app(FormulaFunctionRegistry::class);

        foreach (FormulaFunction::cases() as $function) {
            expect($registry->hasHandler($function))->toBeTrue()
                ->and($registry->handlerFor($function)->formulaFunction())->toBe($function);
        }
    });

});

describe('IF', function (): void {
    test('a true condition yields the then branch and a false condition the else branch', function (): void {
        expect(($this->evaluate)(FormulaFunction::IfThenElse, true, 'a', 'b'))->toBe('a')
            ->and(($this->evaluate)(FormulaFunction::IfThenElse, false, 'a', 'b'))->toBe('b');
    });

    test('the chosen branch is handed back completely untouched', function (): void {
        expect(($this->evaluate)(FormulaFunction::IfThenElse, true, '0.3000000000', 'b'))->toBe('0.3000000000')
            ->and(($this->evaluate)(FormulaFunction::IfThenElse, false, 'a', 7))->toBe(7)
            ->and(($this->evaluate)(FormulaFunction::IfThenElse, true, false, 'b'))->toBeFalse();
    });

    test('a condition that is not a boolean is a type mismatch instead of a truthy cast', function (
        mixed $condition,
    ): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::IfThenElse, $condition, 'a', 'b'),
            FormulaErrorCode::TypeMismatch,
        );
    })->with([
        'the text yes' => ['yes'],
        'the number one' => [1],
        'the number zero' => [0],
        'the text true' => ['true'],
        'null' => [null],
        'an array' => [[]],
    ]);

    test('an error in the chosen branch becomes the result', function (): void {
        $error = ($this->failedArgument)('amount');

        expect(($this->evaluate)(FormulaFunction::IfThenElse, true, $error, 'b'))->toBe($error)
            ->and(($this->evaluate)(FormulaFunction::IfThenElse, false, 'a', $error))->toBe($error);
    });

    test('an error in the branch that was not chosen is ignored', function (): void {
        $error = ($this->failedArgument)('amount');

        expect(($this->evaluate)(FormulaFunction::IfThenElse, false, $error, 'b'))->toBe('b')
            ->and(($this->evaluate)(FormulaFunction::IfThenElse, true, 'a', $error))->toBe('a');
    });
});

describe('SUM', function (): void {
    test('adding tenths decimally yields the exact sum instead of a binary artefact', function (): void {
        $result = ($this->evaluate)(FormulaFunction::Sum, '0.1', '0.2');

        expect($result)->toBeString()
            ->and($result)->toBe('0.3');
    });

    test('floating point tenths are read through the decimal path as well', function (): void {
        expect(($this->evaluate)(FormulaFunction::Sum, 0.1, 0.2))->toBe('0.3');
    });

    test('the sum accepts any number of arguments from one upwards', function (): void {
        expect(($this->evaluate)(FormulaFunction::Sum, 1, 2, 3))->toBe('6')
            ->and(($this->evaluate)(FormulaFunction::Sum, '5'))->toBe('5')
            ->and(($this->evaluate)(FormulaFunction::Sum, 1, 2, 3, 4, 5, 6, 7))->toBe('28');
    });

    test('a negative sum keeps its sign and trailing zeroes are trimmed', function (): void {
        expect(($this->evaluate)(FormulaFunction::Sum, '-2.50', '0.50'))->toBe('-2')
            ->and(($this->evaluate)(FormulaFunction::Sum, '1.500', '0.000'))->toBe('1.5');
    });

    test('a very large number is added without a value error and without precision loss', function (): void {
        expect(($this->evaluate)(FormulaFunction::Sum, 1.0E+20, 1))->toBe('100000000000000000001');
    });

    test('an argument that cannot be read as a number is a type mismatch', function (mixed $argument): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Sum, $argument),
            FormulaErrorCode::TypeMismatch,
        );
    })->with([
        'a word' => ['abc'],
        'a boolean' => [true],
        'an array' => [[]],
        'an object' => [new stdClass],
        'exponential notation' => ['1e3'],
        'a leading space' => [' 1'],
        'an empty string' => [''],
        'two decimal points' => ['1.2.3'],
        'a thousands separator' => ['1,000'],
        'infinity' => [INF],
        'not a number' => [NAN],
    ]);

    test('a null argument is reported as not a number rather than treated as zero', function (): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Sum, null),
            FormulaErrorCode::NotANumber,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Sum, 1, null),
            FormulaErrorCode::NotANumber,
        );
    });

    test('a propagated error wins against a type mismatch in a later argument', function (): void {
        $error = ($this->failedArgument)('amount');

        expect(($this->evaluate)(FormulaFunction::Sum, $error, 'abc'))->toBe($error);
    });
});

describe('ROUND', function (): void {
    test('halves are rounded away from zero rather than to the even neighbour', function (
        mixed $value,
        int $precision,
        string $expected,
    ): void {
        expect(($this->evaluate)(FormulaFunction::Round, $value, $precision))->toBe($expected);
    })->with([
        'two and a half to a whole number' => ['2.5', 0, '3'],
        'minus two and a half to a whole number' => ['-2.5', 0, '-3'],
        'one and a half to a whole number' => ['1.5', 0, '2'],
        'minus one and a half to a whole number' => ['-1.5', 0, '-2'],
        'one point four stays one' => ['1.4', 0, '1'],
        'one thousand two hundred thirty four to hundreds' => [1234, -2, '1200'],
        'one thousand two hundred fifty to hundreds' => [1250, -2, '1300'],
        'a binary trap at two decimals' => ['1.005', 2, '1.01'],
        'another binary trap at two decimals' => ['2.675', 2, '2.68'],
        'a third binary trap at two decimals' => ['8.475', 2, '8.48'],
        'trailing zeroes are trimmed off the result' => ['1.50', 2, '1.5'],
        'rounding to tens' => [1234, -1, '1230'],
    ]);

    test('the precision bounds given by the working scale are accepted', function (): void {
        $scale = (int) config('formulas.scale');

        expect(($this->evaluate)(FormulaFunction::Round, '1.5', $scale))->toBe('1.5')
            ->and(($this->evaluate)(FormulaFunction::Round, 1234, -$scale))->toBe('0');
    });

    test('a precision that is not a whole number is refused', function (mixed $precision): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, '1', $precision),
            FormulaErrorCode::TypeMismatch,
        );
    })->with([
        'a fractional string' => ['2.5'],
        'a fractional float' => [2.5],
        'a word' => ['two'],
    ]);

    test('a precision beyond the working scale is refused instead of allocating', function (): void {
        $scale = (int) config('formulas.scale');
        $start = hrtime(true);

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, '1', 999999999),
            FormulaErrorCode::TypeMismatch,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, '1', -999999999),
            FormulaErrorCode::TypeMismatch,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, '1', $scale + 1),
            FormulaErrorCode::TypeMismatch,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, '1', -$scale - 1),
            FormulaErrorCode::TypeMismatch,
        );

        expect((hrtime(true) - $start) / 1_000_000_000)->toBeLessThan(2.0);
    });

    test('a value that cannot be read as a number is refused', function (): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, 'abc', 2),
            FormulaErrorCode::TypeMismatch,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Round, null, 2),
            FormulaErrorCode::NotANumber,
        );
    });

    test('a null at either number position is reported as not a number', function (
        array $arguments,
    ): void {
        ($this->expectFailure)(
            ($this->apply)(FormulaFunction::Round, $arguments),
            FormulaErrorCode::NotANumber,
        );
    })->with([
        'a null value' => [[null, 2]],
        'a null precision' => [['1', null]],
        'a null precision next to a null value' => [[null, null]],
    ]);

    test('an error argument is handed back before the arity is even looked at', function (): void {
        $error = ($this->failedArgument)('amount');

        expect(($this->apply)(FormulaFunction::Round, [$error]))->toBe($error)
            ->and(($this->evaluate)(FormulaFunction::Round, $error, 2))->toBe($error);
    });
});

describe('CONCAT', function (): void {
    test('text arguments are joined in order without a separator', function (): void {
        expect(($this->evaluate)(FormulaFunction::Concat, 'Nr. ', 'A-7'))->toBe('Nr. A-7')
            ->and(($this->evaluate)(FormulaFunction::Concat, 'a', 'b', 'c'))->toBe('abc')
            ->and(($this->evaluate)(FormulaFunction::Concat, 'only'))->toBe('only');
    });

    test('a number becomes text in a stable dotted notation without trailing zeroes', function (): void {
        expect(($this->evaluate)(FormulaFunction::Concat, 'x', '0.3000000000'))->toBe('x0.3')
            ->and(($this->evaluate)(FormulaFunction::Concat, 5.0))->toBe('5')
            ->and(($this->evaluate)(FormulaFunction::Concat, 1.5))->toBe('1.5')
            ->and(($this->evaluate)(FormulaFunction::Concat, 42))->toBe('42')
            ->and(($this->evaluate)(FormulaFunction::Concat, -0.5))->toBe('-0.5');
    });

    test('a non numeric string is joined verbatim', function (): void {
        expect(($this->evaluate)(FormulaFunction::Concat, 'A-0700', '!'))->toBe('A-0700!')
            ->and(($this->evaluate)(FormulaFunction::Concat, '', 'x'))->toBe('x');
    });

    test('an argument that is neither text nor number is a type mismatch', function (mixed $argument): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::Concat, $argument),
            FormulaErrorCode::TypeMismatch,
        );
    })->with([
        'a boolean' => [true],
        'null' => [null],
        'an array' => [[]],
        'an object' => [new stdClass],
        'a date object' => [new DateTimeImmutable('2026-01-01 00:00:00')],
    ]);

});

describe('DATEDIF', function (): void {
    test('the day difference between two dates is signed and whole', function (): void {
        expect(($this->evaluate)(FormulaFunction::DateDif, '2026-01-01', '2026-01-31', 'days'))->toBe('30')
            ->and(($this->evaluate)(FormulaFunction::DateDif, '2026-01-31', '2026-01-01', 'days'))->toBe('-30')
            ->and(($this->evaluate)(FormulaFunction::DateDif, '2026-01-01', '2026-01-01', 'days'))->toBe('0');
    });

    test('the unit token is accepted in both spellings and any casing', function (string $unit): void {
        expect(($this->evaluate)(FormulaFunction::DateDif, '2026-01-01', '2026-01-31', $unit))->toBe('30');
    })->with([
        'the short token' => ['d'],
        'the short token upper case' => ['D'],
        'the long token' => ['days'],
        'the long token upper case' => ['DAYS'],
        'the long token mixed case' => ['Days'],
        'the long token padded with spaces' => ['  days  '],
    ]);

    test('an unknown unit is a type mismatch rather than a silent day count', function (mixed $unit): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::DateDif, '2026-01-01', '2026-01-31', $unit),
            FormulaErrorCode::TypeMismatch,
        );
    })->with([
        'months' => ['months'],
        'years' => ['y'],
        'an empty string' => [''],
        'null' => [null],
        'a number' => [1],
        'an array' => [[]],
    ]);

    test('date objects and date time strings give the same result as plain dates', function (): void {
        expect(($this->evaluate)(
            FormulaFunction::DateDif,
            new DateTimeImmutable('2026-01-01 23:30:00'),
            new DateTimeImmutable('2026-01-31 00:15:00'),
            'days',
        ))->toBe('30')
            ->and(($this->evaluate)(
                FormulaFunction::DateDif,
                '2026-01-01 23:30:00',
                '2026-01-31 00:15:00',
                'days',
            ))->toBe('30');
    });

    test('a daylight saving change inside the interval does not shorten the day count', function (): void {
        config()->set('app.timezone', 'Europe/Berlin');

        expect(($this->evaluate)(FormulaFunction::DateDif, '2026-03-01', '2026-04-01', 'days'))->toBe('31')
            ->and(($this->evaluate)(FormulaFunction::DateDif, '2026-10-01', '2026-11-01', 'days'))->toBe('31');
    });

    test('a date that cannot be read strictly is reported as an invalid date', function (mixed $date): void {
        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::DateDif, $date, '2026-01-31', 'days'),
            FormulaErrorCode::InvalidDate,
        );

        ($this->expectFailure)(
            ($this->evaluate)(FormulaFunction::DateDif, '2026-01-01', $date, 'days'),
            FormulaErrorCode::InvalidDate,
        );
    })->with([
        'a word' => ['keindatum'],
        'a day that rolls over into the next month' => ['2026-02-30'],
        'the thirty first of february' => ['2026-02-31'],
        'a german date' => ['31.01.2026'],
        'an unpadded date' => ['2026-1-1'],
        'null' => [null],
        'an empty string' => [''],
        'a boolean' => [true],
        'a number' => [20260101],
        'an array' => [[]],
    ]);

});

describe('TODAY', function (): void {
    test('the current date is returned in the application timezone', function (): void {
        config()->set('app.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-07-28 23:30:00', 'UTC'));

        $result = ($this->evaluate)(FormulaFunction::Today);

        expect($result)->toBeString()
            ->and($result)->toBe('2026-07-28');
    });

    test('a timezone ahead of the clock rolls the date forward', function (): void {
        config()->set('app.timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-07-28 23:30:00', 'UTC'));

        expect(($this->evaluate)(FormulaFunction::Today))->toBe('2026-07-29');
    });

    test('a timezone behind the clock rolls the date backward', function (): void {
        config()->set('app.timezone', 'America/Los_Angeles');
        Carbon::setTestNow(Carbon::parse('2026-07-28 01:30:00', 'UTC'));

        expect(($this->evaluate)(FormulaFunction::Today))->toBe('2026-07-27');
    });

    test('the timezone is read again on every call instead of being cached', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-07-28 23:30:00', 'UTC'));

        config()->set('app.timezone', 'UTC');
        $handler = ($this->handlerFor)(FormulaFunction::Today);
        $inUtc = $handler->evaluate([]);

        config()->set('app.timezone', 'Pacific/Auckland');
        $inAuckland = $handler->evaluate([]);

        expect($inUtc)->toBe('2026-07-28')
            ->and($inAuckland)->toBe('2026-07-29');
    });

    test('an error argument is handed back before the arity is looked at', function (): void {
        $error = ($this->failedArgument)('amount');

        expect(($this->apply)(FormulaFunction::Today, [$error]))->toBe($error);
    });
});

describe('Formula function handler guarantees', function (): void {
    test('an error argument is handed back as the very same instance', function (
        FormulaFunction $function,
        array $remainingArguments,
    ): void {
        $error = ($this->failedArgument)('amount');

        $result = ($this->apply)($function, [$error, ...$remainingArguments]);

        expect($result)->toBe($error)
            ->and($result->code)->toBe(FormulaErrorCode::DivisionByZero)
            ->and($result->fieldKey)->toBe('amount');
    })->with([
        'IF' => [FormulaFunction::IfThenElse, ['then', 'else']],
        'SUM' => [FormulaFunction::Sum, []],
        'ROUND' => [FormulaFunction::Round, [2]],
        'CONCAT' => [FormulaFunction::Concat, []],
        'DATEDIF' => [FormulaFunction::DateDif, ['2026-01-31', 'days']],
        'TODAY' => [FormulaFunction::Today, []],
    ]);

    test('an argument count outside the signature is reported as an invalid argument count', function (
        FormulaFunction $function,
        array $arguments,
    ): void {
        ($this->expectFailure)(
            ($this->apply)($function, $arguments),
            FormulaErrorCode::InvalidArgumentCount,
        );
    })->with([
        'TODAY with one argument' => [FormulaFunction::Today, [1]],
        'TODAY with two arguments' => [FormulaFunction::Today, [1, 2]],
        'ROUND with one argument' => [FormulaFunction::Round, ['1']],
        'ROUND with three arguments' => [FormulaFunction::Round, ['1', 2, 3]],
        'IF with two arguments' => [FormulaFunction::IfThenElse, [true, 1]],
        'IF with four arguments' => [FormulaFunction::IfThenElse, [true, 1, 2, 3]],
        'SUM without arguments' => [FormulaFunction::Sum, []],
        'CONCAT without arguments' => [FormulaFunction::Concat, []],
        'DATEDIF with two arguments' => [FormulaFunction::DateDif, ['2026-01-01', '2026-01-31']],
        'DATEDIF with one argument' => [FormulaFunction::DateDif, ['2026-01-01']],
        'DATEDIF with four arguments' => [FormulaFunction::DateDif, ['2026-01-01', '2026-01-31', 'days', 'x']],
    ]);

    test('no handler ever throws for a hostile argument list', function (): void {
        $hostileValues = [null, [], new stdClass, true, false, '', 'abc', '1e3', ' 1', '1.2.3', INF, NAN];

        $shapes = [
            fn (mixed $value): array => [$value],
            fn (mixed $value): array => [$value, $value],
            fn (mixed $value): array => [$value, $value, $value],
            fn (mixed $value): array => [$value, $value, $value, $value],
            fn (mixed $value): array => ['1', $value, $value],
            fn (mixed $value): array => [$value, '1', '1'],
        ];

        $checked = 0;

        foreach (FormulaFunction::cases() as $function) {
            $handler = ($this->handlerFor)($function);

            $empty = $handler->evaluate([]);

            expect($empty instanceof FormulaErrorValue || is_scalar($empty))->toBeTrue();

            foreach ($hostileValues as $value) {
                foreach ($shapes as $shape) {
                    $result = $handler->evaluate($shape($value));

                    expect($result instanceof FormulaErrorValue || is_scalar($result))->toBeTrue();

                    $checked++;
                }
            }
        }

        expect($checked)->toBe(count(FormulaFunction::cases()) * count($hostileValues) * count($shapes));
    });
});
