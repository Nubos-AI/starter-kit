<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaErrorValue;
use App\DTOs\Formulas\FormulaFunctionSignature;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaTypeException;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Formulas\FormulaFunctionRegistry;
use Tests\Support\Doubles\RecordingFormulaFunctionHandler;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

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

    test('a catalog function accepts exactly the argument counts of its arity', function (
        string $name,
        int $argumentCount,
        bool $isAccepted,
        bool $isVariadic,
    ): void {
        $signature = FormulaFunction::from($name)->signature();

        expect($signature->acceptsArgumentCount($argumentCount))->toBe($isAccepted)
            ->and($signature->isVariadic())->toBe($isVariadic);
    })->with([
        'IF with two arguments' => ['IF', 2, false, false],
        'IF with three arguments' => ['IF', 3, true, false],
        'IF with four arguments' => ['IF', 4, false, false],
        'TODAY without arguments' => ['TODAY', 0, true, false],
        'TODAY with one argument' => ['TODAY', 1, false, false],
        'SUM without arguments' => ['SUM', 0, false, true],
        'SUM with one argument' => ['SUM', 1, true, true],
        'SUM with fifty arguments' => ['SUM', 50, true, true],
        'CONCAT without arguments' => ['CONCAT', 0, false, true],
        'CONCAT with one argument' => ['CONCAT', 1, true, true],
        'CONCAT with fifty arguments' => ['CONCAT', 50, true, true],
    ]);

});

describe('Formula value types', function (): void {

    test('a formula value type offers exactly the filter operators of its native field type', function (FormulaValueType $valueType, FieldType $fieldType): void {
        $native = ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid("native-{$fieldType->value}"),
            'key' => 'native',
            'field_type' => $fieldType,
            'is_filterable' => true,
            'is_encrypted' => false,
            'config' => [],
        ]);

        expect($valueType->filterOperators())->toBe(app(FieldTypeRegistry::class)->filterOperators($native));
    })->with([
        'number' => [FormulaValueType::Number, FieldType::Number],
        'text' => [FormulaValueType::Text, FieldType::TextShort],
        'date' => [FormulaValueType::Date, FieldType::Date],
        'boolean' => [FormulaValueType::Boolean, FieldType::Boolean],
    ]);
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
    test('an error value survives a json round trip', function (FormulaErrorCode $code, ?string $fieldKey): void {
        $error = new FormulaErrorValue($code, $fieldKey);

        $payload = $error->toArray();

        expect($payload['code'])->toBe($code->value)
            ->and($payload['field_key'] ?? null)->toBe($fieldKey);

        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = FormulaErrorValue::fromArray($decoded);

        expect($restored->code)->toBe($code)
            ->and($restored->fieldKey)->toBe($fieldKey);
    })->with([
        'without a field key' => [FormulaErrorCode::DivisionByZero, null],
        'with a field key' => [FormulaErrorCode::UnknownFieldReference, 'net_amount'],
    ]);
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

    test('asking a fixed arity signature for an argument beyond its arity is refused', function (): void {
        expect(fn (): ?FormulaValueType => FormulaFunction::Round->signature()->expectedTypeAt(2))
            ->toThrow(FormulaTypeException::class)
            ->and(fn (): ?FormulaValueType => FormulaFunction::DateDif->signature()->expectedTypeAt(3))
            ->toThrow(FormulaTypeException::class)
            ->and(fn (): ?FormulaValueType => FormulaFunction::Today->signature()->expectedTypeAt(0))
            ->toThrow(FormulaTypeException::class);
    });

    test('an inconsistent signature is refused at construction', function (int $minArgs, ?int $maxArgs, array $argumentTypes): void {
        expect(fn (): FormulaFunctionSignature => new FormulaFunctionSignature(
            minArgs: $minArgs,
            maxArgs: $maxArgs,
            argumentTypes: $argumentTypes,
            resultType: FormulaValueType::Number,
        ))->toThrow(FormulaTypeException::class);
    })->with([
        'a maximum arity below the minimum' => [3, 1, [FormulaValueType::Number]],
        'a negative minimum arity' => [-1, 2, [FormulaValueType::Number, FormulaValueType::Number]],
        'a fixed arity without one argument type per accepted argument' => [1, 2, [FormulaValueType::Number]],
        'a variadic signature without any argument type' => [1, null, []],
    ]);
});

describe('Formula function registry', function (): void {
    test('a registered function resolves to an instance of its mapped handler class', function (): void {
        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => RecordingFormulaFunctionHandler::class,
        ]]);

        $registry = app(FormulaFunctionRegistry::class);

        expect($registry->hasHandler(FormulaFunction::Sum))->toBeTrue()
            ->and($registry->handlerFor(FormulaFunction::Sum))->toBeInstanceOf(RecordingFormulaFunctionHandler::class)
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
            FormulaFunction::Sum->value => RecordingFormulaFunctionHandler::class,
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
