<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaErrorValue;
use App\DTOs\Formulas\FormulaFunctionSignature;
use App\Enums\CustomFields\FilterOperator;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaTypeException;
use App\Support\Formulas\FormulaFunctionRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

class FakeSumFormulaFunctionHandler implements FormulaFunctionHandler
{
    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed
    {
        throw new RuntimeException('evaluate is out of scope for this catalog test.');
    }

    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Sum;
    }
}

describe('Formula function catalog', function (): void {

    test('every catalog function declares its arity, argument types and result type', function (
        string $name,
        int $minArgs,
        ?int $maxArgs,
        array $argumentTypeValues,
        ?string $resultTypeValue,
    ): void {
        $signature = FormulaFunction::from($name)->signature();

        $expectedArgumentTypes = array_map(
            static fn (?string $value): ?FormulaValueType => $value === null ? null : FormulaValueType::from($value),
            $argumentTypeValues,
        );

        expect($signature)->toBeInstanceOf(FormulaFunctionSignature::class)
            ->and($signature->minArgs)->toBe($minArgs)
            ->and($signature->maxArgs)->toBe($maxArgs)
            ->and($signature->argumentTypes)->toBe($expectedArgumentTypes)
            ->and($signature->resultType)->toBe($resultTypeValue === null ? null : FormulaValueType::from($resultTypeValue));
    })->with([
        'IF' => ['IF', 3, 3, ['boolean', null, null], null],
        'SUM' => ['SUM', 1, null, ['number'], 'number'],
        'ROUND' => ['ROUND', 2, 2, ['number', 'number'], 'number'],
        'CONCAT' => ['CONCAT', 1, null, ['text'], 'text'],
        'DATEDIF' => ['DATEDIF', 3, 3, ['date', 'date', 'text'], 'number'],
        'TODAY' => ['TODAY', 0, 0, [], 'date'],
    ]);

    test('IF accepts three arguments and nothing else', function (): void {
        $signature = FormulaFunction::IfThenElse->signature();

        expect($signature->acceptsArgumentCount(2))->toBeFalse()
            ->and($signature->acceptsArgumentCount(3))->toBeTrue()
            ->and($signature->acceptsArgumentCount(4))->toBeFalse()
            ->and($signature->isVariadic())->toBeFalse();
    });

    test('TODAY accepts no arguments at all', function (): void {
        $signature = FormulaFunction::Today->signature();

        expect($signature->acceptsArgumentCount(0))->toBeTrue()
            ->and($signature->acceptsArgumentCount(1))->toBeFalse()
            ->and($signature->isVariadic())->toBeFalse();
    });

    test('SUM and CONCAT are variadic and swallow an arbitrary number of arguments', function (
        string $name,
    ): void {
        $signature = FormulaFunction::from($name)->signature();

        expect($signature->isVariadic())->toBeTrue()
            ->and($signature->maxArgs)->toBeNull()
            ->and($signature->acceptsArgumentCount(0))->toBeFalse()
            ->and($signature->acceptsArgumentCount(1))->toBeTrue()
            ->and($signature->acceptsArgumentCount(50))->toBeTrue();
    })->with([
        'SUM' => ['SUM'],
        'CONCAT' => ['CONCAT'],
    ]);

});

describe('Formula value types', function (): void {

    test('the number value type offers the operators of the native number field', function (): void {
        expect(FormulaValueType::Number->filterOperators())->toBe([
            FilterOperator::Equals,
            FilterOperator::NotEqual,
            FilterOperator::GreaterThan,
            FilterOperator::GreaterThanOrEqual,
            FilterOperator::LessThan,
            FilterOperator::LessThanOrEqual,
            FilterOperator::InRange,
            FilterOperator::Blank,
            FilterOperator::NotBlank,
        ]);
    });

    test('the text value type offers the operators of the native short text field', function (): void {
        expect(FormulaValueType::Text->filterOperators())->toBe([
            FilterOperator::Equals,
            FilterOperator::NotEqual,
            FilterOperator::Contains,
            FilterOperator::NotContains,
            FilterOperator::StartsWith,
            FilterOperator::EndsWith,
            FilterOperator::In,
            FilterOperator::NotIn,
            FilterOperator::Blank,
            FilterOperator::NotBlank,
        ]);
    });

    test('the date value type offers the operators of the native date field', function (): void {
        expect(FormulaValueType::Date->filterOperators())->toBe([
            FilterOperator::Equals,
            FilterOperator::NotEqual,
            FilterOperator::GreaterThan,
            FilterOperator::LessThan,
            FilterOperator::InRange,
            FilterOperator::Blank,
            FilterOperator::NotBlank,
        ]);
    });

    test('the boolean value type offers the operators of the native boolean field', function (): void {
        expect(FormulaValueType::Boolean->filterOperators())->toBe([
            FilterOperator::Equals,
            FilterOperator::Blank,
        ]);
    });
});

describe('Formula error codes', function (): void {
    test('every error code carries a distinct non-empty cause', function (): void {
        expect(FormulaErrorCode::cases())->not->toBeEmpty();

        $labels = [];

        foreach (FormulaErrorCode::cases() as $code) {
            $label = $code->label();

            expect($label)->toBeString()
                ->and(trim($label))->not->toBe('');

            $labels[] = $label;
        }

        expect($labels)->toHaveCount(count(array_unique($labels)));
    });

});

describe('Formula error values', function (): void {
    test('an error value without a field key survives a json round trip', function (): void {
        $error = new FormulaErrorValue(FormulaErrorCode::DivisionByZero);

        $payload = $error->toArray();

        expect($payload['code'])->toBe(FormulaErrorCode::DivisionByZero->value)
            ->and($payload['field_key'] ?? null)->toBeNull();

        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = FormulaErrorValue::fromArray($decoded);

        expect($restored->code)->toBe(FormulaErrorCode::DivisionByZero)
            ->and($restored->fieldKey)->toBeNull();
    });

    test('an error value with a field key survives a json round trip', function (): void {
        $error = new FormulaErrorValue(FormulaErrorCode::UnknownFieldReference, 'net_amount');

        $payload = $error->toArray();

        expect($payload['code'])->toBe(FormulaErrorCode::UnknownFieldReference->value)
            ->and($payload['field_key'])->toBe('net_amount');

        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = FormulaErrorValue::fromArray($decoded);

        expect($restored->code)->toBe(FormulaErrorCode::UnknownFieldReference)
            ->and($restored->fieldKey)->toBe('net_amount');
    });
});

describe('Formula function signatures', function (): void {
    test('a variadic signature clamps the expected argument type to its last entry', function (): void {
        $sum = FormulaFunction::Sum->signature();
        $concat = FormulaFunction::Concat->signature();

        expect($sum->expectedTypeAt(0))->toBe(FormulaValueType::Number)
            ->and($sum->expectedTypeAt(7))->toBe(FormulaValueType::Number)
            ->and($concat->expectedTypeAt(0))->toBe(FormulaValueType::Text)
            ->and($concat->expectedTypeAt(3))->toBe(FormulaValueType::Text);
    });

    test('IF fixes its condition to boolean and leaves both branches context dependent', function (): void {
        $signature = FormulaFunction::IfThenElse->signature();

        expect($signature->expectedTypeAt(0))->toBe(FormulaValueType::Boolean)
            ->and($signature->expectedTypeAt(1))->toBeNull()
            ->and($signature->expectedTypeAt(2))->toBeNull();
    });

    test('DATEDIF expects two dates followed by a text unit', function (): void {
        $signature = FormulaFunction::DateDif->signature();

        expect($signature->expectedTypeAt(0))->toBe(FormulaValueType::Date)
            ->and($signature->expectedTypeAt(1))->toBe(FormulaValueType::Date)
            ->and($signature->expectedTypeAt(2))->toBe(FormulaValueType::Text);
    });

    test('asking a fixed arity signature for an argument beyond its arity is refused', function (): void {
        expect(fn (): ?FormulaValueType => FormulaFunction::Round->signature()->expectedTypeAt(2))
            ->toThrow(FormulaTypeException::class)
            ->and(fn (): ?FormulaValueType => FormulaFunction::DateDif->signature()->expectedTypeAt(3))
            ->toThrow(FormulaTypeException::class)
            ->and(fn (): ?FormulaValueType => FormulaFunction::Today->signature()->expectedTypeAt(0))
            ->toThrow(FormulaTypeException::class);
    });

    test('a signature whose maximum arity is below its minimum is refused at construction', function (): void {
        expect(fn (): FormulaFunctionSignature => new FormulaFunctionSignature(
            minArgs: 3,
            maxArgs: 1,
            argumentTypes: [FormulaValueType::Number],
            resultType: FormulaValueType::Number,
        ))->toThrow(FormulaTypeException::class);
    });

    test('a signature with a negative minimum arity is refused at construction', function (): void {
        expect(fn (): FormulaFunctionSignature => new FormulaFunctionSignature(
            minArgs: -1,
            maxArgs: 2,
            argumentTypes: [FormulaValueType::Number, FormulaValueType::Number],
            resultType: FormulaValueType::Number,
        ))->toThrow(FormulaTypeException::class);
    });

    test('a fixed arity signature must declare one argument type per accepted argument', function (): void {
        expect(fn (): FormulaFunctionSignature => new FormulaFunctionSignature(
            minArgs: 1,
            maxArgs: 2,
            argumentTypes: [FormulaValueType::Number],
            resultType: FormulaValueType::Number,
        ))->toThrow(FormulaTypeException::class);
    });

    test('a variadic signature must declare at least one argument type', function (): void {
        expect(fn (): FormulaFunctionSignature => new FormulaFunctionSignature(
            minArgs: 1,
            maxArgs: null,
            argumentTypes: [],
            resultType: FormulaValueType::Number,
        ))->toThrow(FormulaTypeException::class);
    });
});

describe('Formula function registry', function (): void {
    test('a registered function resolves to an instance of its mapped handler class', function (): void {
        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => FakeSumFormulaFunctionHandler::class,
        ]]);

        $registry = app(FormulaFunctionRegistry::class);

        expect($registry->hasHandler(FormulaFunction::Sum))->toBeTrue()
            ->and($registry->handlerFor(FormulaFunction::Sum))->toBeInstanceOf(FakeSumFormulaFunctionHandler::class)
            ->and($registry->handlerFor(FormulaFunction::Sum)->formulaFunction())->toBe(FormulaFunction::Sum);
    });

    test('an absent handler map reports no handler without throwing', function (): void {
        config(['formulas.function_handlers' => null]);

        $registry = app(FormulaFunctionRegistry::class);

        foreach (FormulaFunction::cases() as $function) {
            expect($registry->hasHandler($function))->toBeFalse();
        }
    });

    test('a handler map that omits the function reports no handler without throwing', function (): void {
        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => FakeSumFormulaFunctionHandler::class,
        ]]);

        $registry = app(FormulaFunctionRegistry::class);

        expect($registry->hasHandler(FormulaFunction::Concat))->toBeFalse()
            ->and($registry->hasHandler(FormulaFunction::Today))->toBeFalse();
    });

    test('a mapped value that is not a handler class reports no handler without throwing', function (
        mixed $mapped,
    ): void {
        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => $mapped,
        ]]);

        $registry = app(FormulaFunctionRegistry::class);

        expect($registry->hasHandler(FormulaFunction::Sum))->toBeFalse();
    })->with([
        'a class that does not exist' => ['App\\Handlers\\Formulas\\NonExistentSumHandler'],
        'a class that does not implement the contract' => [stdClass::class],
        'a value that is not a string at all' => [42],
    ]);

    test('resolving an unregistered function throws and carries the offending function', function (): void {
        config(['formulas.function_handlers' => []]);

        $registry = app(FormulaFunctionRegistry::class);

        try {
            $registry->handlerFor(FormulaFunction::Round);
            $this->fail('expected FormulaTypeException for an unregistered formula function');
        } catch (FormulaTypeException $exception) {
            expect($exception->formulaFunction)->toBe(FormulaFunction::Round)
                ->and($exception->getMessage())->not->toBe('');
        }
    });

    test('resolving a function whose mapped class is not a handler throws', function (): void {
        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => stdClass::class,
        ]]);

        $registry = app(FormulaFunctionRegistry::class);

        expect(fn (): FormulaFunctionHandler => $registry->handlerFor(FormulaFunction::Sum))
            ->toThrow(FormulaTypeException::class);
    });
});
