<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaValueType;
use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Formulas\FormulaFieldTypeMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

class ComputedHandlerFunctionDouble implements FormulaFunctionHandler
{
    public mixed $result = 0;

    /**
     * @param  list<mixed>  $arguments
     */
    public function evaluate(array $arguments): mixed
    {
        return $this->result;
    }

    public function formulaFunction(): FormulaFunction
    {
        return FormulaFunction::Sum;
    }
}

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('object-type');

    /** @var callable(string, FieldType, ?array<string, mixed>, bool):FieldDefinition */
    $this->field = function (
        string $key,
        FieldType $type,
        ?array $config = null,
        bool $isEncrypted = false,
    ): FieldDefinition {
        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => $isEncrypted,
            'is_sortable' => true,
            'is_filterable' => true,
            'config' => $config,
        ]);
    };

    /** @var callable(string, ?string, ?string):FieldDefinition */
    $this->computed = function (string $key, ?string $formula, ?string $resultType): FieldDefinition {
        $config = [];

        if ($formula !== null) {
            $config['formula'] = $formula;
        }

        if ($resultType !== null) {
            $config['result_type'] = $resultType;
        }

        return ($this->field)($key, FieldType::Computed, $config);
    };

    /** @var callable(?array<string, mixed>, ?string):CustomRecord */
    $this->record = function (?array $data, ?string $tenantId = null): CustomRecord {
        return ModelStub::make(CustomRecord::class, [
            'id' => ModelStub::ulid('record'),
            'tenant_id' => $tenantId ?? (string) $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'data' => $data,
        ]);
    };

    /** @var callable(list<FieldDefinition>):ComputedFieldHandler */
    $this->handler = function (array $fields): ComputedFieldHandler {
        app()->instance(
            ObjectTypeFieldLookup::class,
            StaticObjectTypeFieldLookup::carrying($this->objectTypeId, $fields),
        );

        return app(ComputedFieldHandler::class);
    };

    /** @var callable(list<FieldDefinition>, FieldDefinition, CustomRecord):mixed */
    $this->compute = function (array $fields, FieldDefinition $field, CustomRecord $record): mixed {
        return ($this->handler)($fields)->compute($field, $record);
    };

    /** @var callable(list<FieldDefinition>, FieldDefinition, CustomRecord):FormulaErrorValue */
    $this->errorOf = function (array $fields, FieldDefinition $field, CustomRecord $record): FormulaErrorValue {
        $value = ($this->compute)($fields, $field, $record);

        expect($value)->toBeInstanceOf(FormulaErrorValue::class);

        return $value;
    };

    /** @var callable(list<FieldDefinition>, FieldDefinition, CustomRecord):?QueryShape */
    $this->writeAttemptOf = function (array $fields, FieldDefinition $field, CustomRecord $record): ?QueryShape {
        $handler = ($this->handler)($fields);

        return QueryShape::attemptedBy(fn (): mixed => $handler->materialize($field, $record));
    };

    /** @var callable(mixed, string):void */
    $this->expectDecimal = function (mixed $value, string $expected): void {
        expect($value)->toBeString()
            ->and(is_numeric($value))->toBeTrue()
            ->and(bccomp((string) $value, $expected, 10))->toBe(0);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

describe('ComputedFieldHandler registration', function (): void {
    test('the computed field type resolves to a handler that declares itself computed', function (): void {
        $registry = app(FieldTypeRegistry::class);

        expect($registry->hasHandler(FieldType::Computed))->toBeTrue()
            ->and($registry->handlerFor(FieldType::Computed))->toBeInstanceOf(ComputedFieldHandler::class)
            ->and($registry->handlerFor(FieldType::Computed)->fieldType())->toBe(FieldType::Computed);
    });

    test('a computed field is never written by the user and therefore casts to null', function (): void {
        $field = ($this->computed)('brutto', '{netto} * 1,19', 'number');
        $handler = ($this->handler)([$field]);

        expect($handler->cast('999', $field))->toBeNull()
            ->and($handler->cast(null, $field))->toBeNull();
    });

    test('a computed field contributes only a nullable validation rule', function (): void {
        $field = ($this->computed)('brutto', '{netto} * 1,19', 'number');

        expect(($this->handler)([$field])->validationRules($field))->toBe(['nullable']);
    });

    test('the offered filter operators follow the configured result type', function (
        ?string $resultType,
        bool $expectsOperators,
    ): void {
        $field = ($this->computed)('result', '1 + 1', $resultType);

        $operators = ($this->handler)([$field])->filterOperators($field);

        expect($operators === [])->toBe(!$expectsOperators);

        if ($expectsOperators) {
            expect($operators)->toBe(FormulaValueType::from((string) $resultType)->filterOperators());
        }
    })->with([
        'a numeric result type' => ['number', true],
        'a text result type' => ['text', true],
        'a missing result type' => [null, false],
        'an unusable result type' => ['bogus', false],
    ]);
});

describe('ComputedFieldHandler computes a typed result', function (): void {
    test('a date function is evaluated against the frozen application clock', function (): void {
        $this->travelTo(Carbon::parse('2026-03-04 12:00:00'));

        $today = ($this->computed)('today_field', 'TODAY()', 'date');

        expect(($this->compute)([$today], $today, ($this->record)([])))->toBe('2026-03-04');

        $this->travelBack();
    });
});

describe('ComputedFieldHandler builds its context from the field definitions', function (): void {
    test('a defined field without a stored value is not a number rather than an unknown reference', function (): void {
        $empty = ($this->field)('empty_number', FieldType::Number);
        $total = ($this->computed)('total', '{empty_number} + 1', 'number');

        $error = ($this->errorOf)([$empty, $total], $total, ($this->record)([]));

        expect($error->code)->toBe(FormulaErrorCode::NotANumber)
            ->and($error->fieldKey)->toBe('empty_number');
    });

    test('a key that no field definition declares is an unknown reference even when the record carries it', function (): void {
        $total = ($this->computed)('total', '{ghost} + 1', 'number');

        $error = ($this->errorOf)([$total], $total, ($this->record)(['ghost' => 41]));

        expect($error->code)->toBe(FormulaErrorCode::UnknownFieldReference)
            ->and($error->fieldKey)->toBe('ghost');
    });

    test('an encrypted input field is never decrypted and its ciphertext never reaches the write', function (): void {
        $secret = ($this->field)('secret', FieldType::TextShort, null, true);
        $doubled = ($this->computed)('secret_doubled', '{secret} * 2', 'number');

        $cipher = Crypt::encryptString(json_encode(4711, JSON_THROW_ON_ERROR));
        $record = ($this->record)(['secret' => $cipher]);

        $error = ($this->errorOf)([$secret, $doubled], $doubled, $record);

        expect($error->code)->toBe(FormulaErrorCode::TypeMismatch);

        $write = ($this->writeAttemptOf)([$secret, $doubled], $doubled, $record);

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain('?::jsonb')
            ->and($write->hasBinding($cipher))->toBeFalse();

        foreach ($write->bindings as $binding) {
            expect((string) $binding)->not->toContain('4711');
        }
    });
});

describe('ComputedFieldHandler never throws on a runtime failure', function (): void {
    test('a runtime failure becomes a named error value', function (
        string $formula,
        string $resultType,
        FormulaErrorCode $expected,
    ): void {
        $fields = [
            ($this->field)('netto', FieldType::Number),
            ($this->field)('label', FieldType::TextShort),
            ($this->field)('empty_number', FieldType::Number),
            ($this->field)('start_date', FieldType::Date),
        ];

        $field = ($this->computed)('result', $formula, $resultType);
        $record = ($this->record)(['netto' => 100, 'label' => 'abc', 'start_date' => '2026-01-31']);

        expect(($this->errorOf)([...$fields, $field], $field, $record)->code)->toBe($expected);
    })->with([
        'a division by zero' => ['1 / 0', 'number', FormulaErrorCode::DivisionByZero],
        'a divisor field that holds zero' => ['{netto} / 0', 'number', FormulaErrorCode::DivisionByZero],
        'a reference to a field that does not exist' => ['{does_not_exist} + 1', 'number', FormulaErrorCode::UnknownFieldReference],
        'a defined field without a value' => ['{empty_number} + 1', 'number', FormulaErrorCode::NotANumber],
        'a text field used in arithmetic' => ['{label} * 2', 'number', FormulaErrorCode::TypeMismatch],
        'a text literal used as a date' => ['DATEDIF("nodate"; {start_date}; "days")', 'number', FormulaErrorCode::InvalidDate],
    ]);

    test('exhausting the evaluation budget becomes a named error value', function (): void {
        $field = ($this->computed)('result', '1 + 2 + 3 + 4', 'number');
        $record = ($this->record)([]);

        ($this->expectDecimal)(($this->compute)([$field], $field, $record), '10');

        config(['formulas.max_evaluation_steps' => 3]);

        expect(($this->errorOf)([$field], $field, $record)->code)->toBe(FormulaErrorCode::EvaluationLimitExceeded);
    });

    test('an error a function handler returns is propagated unchanged', function (): void {
        $double = new ComputedHandlerFunctionDouble;
        $double->result = new FormulaErrorValue(FormulaErrorCode::InvalidArgumentCount, 'netto');

        config(['formulas.function_handlers' => [
            FormulaFunction::Sum->value => ComputedHandlerFunctionDouble::class,
        ]]);
        app()->instance(ComputedHandlerFunctionDouble::class, $double);

        $field = ($this->computed)('result', 'SUM(1)', 'number');

        $error = ($this->errorOf)([$field], $field, ($this->record)([]));

        expect($error->code)->toBe(FormulaErrorCode::InvalidArgumentCount)
            ->and($error->fieldKey)->toBe('netto');
    });

    test('a broken configuration becomes a named error value instead of an exception', function (
        ?string $formula,
        ?string $resultType,
    ): void {
        $field = ($this->computed)('result', $formula, $resultType);

        expect(($this->errorOf)([$field], $field, ($this->record)([]))->code)
            ->toBe(FormulaErrorCode::InvalidConfiguration);
    })->with([
        'a missing formula' => [null, 'number'],
        'an empty formula' => ['', 'number'],
        'a blank formula' => ['   ', 'number'],
        'a missing result type' => ['1 + 1', null],
        'an unusable result type' => ['1 + 1', 'bogus'],
        'a formula that no longer parses' => ['1 +', 'number'],
        'an unknown function' => ['NOPE(1)', 'number'],
    ]);

    test('a result that contradicts the configured result type becomes a named error value', function (
        string $formula,
        string $resultType,
        FormulaErrorCode $expected,
    ): void {
        $fields = [
            ($this->field)('netto', FieldType::Number),
            ($this->field)('label', FieldType::TextShort),
        ];

        $field = ($this->computed)('result', $formula, $resultType);
        $record = ($this->record)(['netto' => 100, 'label' => 'abc']);

        expect(($this->errorOf)([...$fields, $field], $field, $record)->code)->toBe($expected);
    })->with([
        'a text result declared as a number' => ['CONCAT("a"; "b")', 'number', FormulaErrorCode::TypeMismatch],
        'a numeric result declared as a boolean' => ['{netto} + 1', 'boolean', FormulaErrorCode::TypeMismatch],
        'a text result declared as a date' => ['{label}', 'date', FormulaErrorCode::InvalidDate],
    ]);

    test('the configuration error code carries its own value and label', function (): void {
        expect(FormulaErrorCode::InvalidConfiguration->value)->toBe('invalid_configuration')
            ->and(trim(FormulaErrorCode::InvalidConfiguration->label()))->not->toBe('');

        $labels = array_map(
            static fn (FormulaErrorCode $code): string => $code->label(),
            FormulaErrorCode::cases(),
        );

        expect($labels)->toHaveCount(count(array_unique($labels)));
    });
});

describe('ComputedFieldHandler materialises into custom_records.data', function (): void {
    test('the write form follows the configured result type', function (
        string $formula,
        string $resultType,
        array $data,
        string $expectedExpression,
        string $expectedBinding,
    ): void {
        $fields = [
            ($this->field)('netto', FieldType::Number),
            ($this->field)('start_date', FieldType::Date),
        ];

        $field = ($this->computed)('result', $formula, $resultType);

        $write = ($this->writeAttemptOf)([...$fields, $field], $field, ($this->record)($data));

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain($expectedExpression)
            ->and($write->hasBinding($expectedBinding))->toBeTrue();
    })->with([
        'a true boolean result' => ['{netto} > 100', 'boolean', ['netto' => 150], 'to_jsonb(?::boolean)', 'true'],
        'a false boolean result' => ['{netto} > 100', 'boolean', ['netto' => 50], 'to_jsonb(?::boolean)', 'false'],
        'a text result' => ['CONCAT("EUR "; {netto})', 'text', ['netto' => 100], 'to_jsonb(?::text)', 'EUR 100'],
        'a date result' => ['{start_date}', 'date', ['start_date' => '2026-01-31'], 'to_jsonb(?::text)', '2026-01-31'],
    ]);

    test('a large decimal reaches the write without losing a digit', function (
        string $formula,
        string $amount,
        string $expected,
    ): void {
        $amountField = ($this->field)('amount', FieldType::Money);
        $field = ($this->computed)('exact', $formula, 'number');

        $write = ($this->writeAttemptOf)([$amountField, $field], $field, ($this->record)(['amount' => $amount]));

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain('to_jsonb(?::numeric)');

        ($this->expectDecimal)($write->bindings[1], $expected);
    })->with([
        'thirteen significant digits' => ['{amount} + 0', '12345678901.23', '12345678901.23'],
        'a quotient that fills the configured scale' => ['{amount} / 3', '1000000', '333333.3333333333'],
        'an amount at the edge of double precision' => ['{amount} + 0', '99999999999999.9', '99999999999999.9'],
        'sixteen significant digits' => ['{amount} + 0', '123456789.1234567', '123456789.1234567'],
    ]);

    test('an error value is written as a json object that reads back with its cause', function (): void {
        $empty = ($this->field)('empty_number', FieldType::Number);
        $field = ($this->computed)('result', '{empty_number} + 1', 'number');

        $write = ($this->writeAttemptOf)([$empty, $field], $field, ($this->record)([]));

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain('?::jsonb');

        $stored = json_decode((string) $write->bindings[1], true, 512, JSON_THROW_ON_ERROR);
        $error = FormulaErrorValue::fromArray($stored);

        expect($error->code)->toBe(FormulaErrorCode::NotANumber)
            ->and($error->fieldKey)->toBe('empty_number');
    });

    test('a record whose data column is not an object gets one created by the write itself', function (): void {
        $field = ($this->computed)('result', '2 + 3', 'number');

        $write = ($this->writeAttemptOf)([$field], $field, ($this->record)(null));

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain("jsonb_typeof(data) = 'object'")
            ->and($write->sql)->toContain("else '{}'::jsonb");
    });

    test('the write addresses the single row it was handed and never leaves its tenant', function (): void {
        $netto = ($this->field)('netto', FieldType::Number);
        $field = ($this->computed)('brutto', '{netto} * 2', 'number');
        $record = ($this->record)(['netto' => 50]);

        $write = ($this->writeAttemptOf)([$netto, $field], $field, $record);

        expect($write)->not->toBeNull()
            ->and($write->sql)->toContain('WHERE id = ? AND tenant_id = ?')
            ->and($write->hasBinding((string) $record->getKey()))->toBeTrue()
            ->and($write->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
    });

    test('a record handed with a foreign tenant carries that tenant into the write and never the acting one', function (): void {
        $foreignTenantId = ModelStub::ulid('foreign-tenant');
        $netto = ($this->field)('netto', FieldType::Number);
        $field = ($this->computed)('brutto', '{netto} * 2', 'number');
        $record = ($this->record)(['netto' => 50], $foreignTenantId);

        $write = ($this->writeAttemptOf)([$netto, $field], $field, $record);

        expect($write)->not->toBeNull()
            ->and($write->hasBinding($foreignTenantId))->toBeTrue()
            ->and($write->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
    });

    test('the write names the field key as its json path', function (): void {
        $netto = ($this->field)('netto', FieldType::Number);
        $field = ($this->computed)('brutto', '{netto} * 2', 'number');

        $write = ($this->writeAttemptOf)([$netto, $field], $field, ($this->record)(['netto' => 50]));

        expect($write)->not->toBeNull()
            ->and($write->bindings[0])->toBe('{brutto}');
    });
});

describe('The numeric index decision is instance based', function (): void {
    test('a field decides on its own instance whether it is numerically indexed', function (
        FieldType $type,
        ?array $config,
        bool $expected,
    ): void {
        $field = ModelStub::make(FieldDefinition::class, [
            'field_type' => $type,
            'config' => $config,
        ]);

        expect($field->usesNumericIndex())->toBe($expected);
    })->with([
        'a number field' => [FieldType::Number, null, true],
        'a decimal field' => [FieldType::Decimal, null, true],
        'a money field' => [FieldType::Money, null, true],
        'a short text field' => [FieldType::TextShort, null, false],
        'a date field' => [FieldType::Date, null, false],
        'a boolean field' => [FieldType::Boolean, null, false],
        'a rollup field keeps its text expression' => [
            FieldType::Rollup,
            ['aggregate' => 'sum', 'source_field_key' => 'amount'],
            false,
        ],
        'a computed field with a numeric result' => [
            FieldType::Computed,
            ['formula' => '1 + 1', 'result_type' => 'number'],
            true,
        ],
        'a computed field with a text result' => [
            FieldType::Computed,
            ['formula' => '"a"', 'result_type' => 'text'],
            false,
        ],
        'a computed field with a date result' => [
            FieldType::Computed,
            ['formula' => 'TODAY()', 'result_type' => 'date'],
            false,
        ],
        'a computed field with a boolean result' => [
            FieldType::Computed,
            ['formula' => '1 > 0', 'result_type' => 'boolean'],
            false,
        ],
        'a computed field without a result type' => [
            FieldType::Computed,
            ['formula' => '1 + 1'],
            false,
        ],
        'a computed field with an unusable result type' => [
            FieldType::Computed,
            ['formula' => '1 + 1', 'result_type' => 'bogus'],
            false,
        ],
    ]);

    test('the bare field type keeps its own numeric base case', function (): void {
        expect(FieldType::Number->isNumericIndex())->toBeTrue()
            ->and(FieldType::Decimal->isNumericIndex())->toBeTrue()
            ->and(FieldType::Money->isNumericIndex())->toBeTrue()
            ->and(FieldType::Rollup->isNumericIndex())->toBeFalse()
            ->and(FieldType::Computed->isNumericIndex())->toBeFalse()
            ->and(FieldType::TextShort->isNumericIndex())->toBeFalse();
    });

    test('the sort expression follows the configured result type', function (
        string $resultType,
        bool $expectsNumeric,
    ): void {
        $field = ($this->computed)('result', '1 + 1', $resultType);

        expect(str_contains(app(IndexRegistry::class)->sortExpression($field), '::numeric'))->toBe($expectsNumeric);
    })->with([
        'a numeric result type' => ['number', true],
        'a text result type' => ['text', false],
    ]);

    test('the result type is read from the configuration exactly once', function (
        FieldType $type,
        ?array $config,
        ?FormulaValueType $expected,
    ): void {
        $field = ModelStub::make(FieldDefinition::class, [
            'field_type' => $type,
            'config' => $config,
        ]);

        expect($field->resultType())->toBe($expected);
    })->with([
        'a numeric result type' => [FieldType::Computed, ['result_type' => 'number'], FormulaValueType::Number],
        'a text result type' => [FieldType::Computed, ['result_type' => 'text'], FormulaValueType::Text],
        'a date result type' => [FieldType::Computed, ['result_type' => 'date'], FormulaValueType::Date],
        'a boolean result type' => [FieldType::Computed, ['result_type' => 'boolean'], FormulaValueType::Boolean],
        'an unusable result type' => [FieldType::Computed, ['result_type' => 'bogus'], null],
        'a missing configuration' => [FieldType::Computed, null, null],
        'a rollup field' => [FieldType::Rollup, ['aggregate' => 'sum'], null],
    ]);

    test('the formula type mapper reads the result type through the field instance', function (): void {
        $mapper = app(FormulaFieldTypeMapper::class);

        expect($mapper->map(($this->computed)('numeric_result', '1 + 1', 'number')))->toBe(FormulaValueType::Number)
            ->and($mapper->map(($this->computed)('text_result', '"a"', 'text')))->toBe(FormulaValueType::Text)
            ->and($mapper->map(($this->computed)('broken_result', '1 + 1', 'bogus')))->toBeNull();
    });
});

test('isTemporal is true for exactly the date-carrying field types', function (): void {
    $temporal = array_values(array_filter(
        FieldType::cases(),
        static fn (FieldType $type): bool => $type->isTemporal(),
    ));

    expect($temporal)->toBe([FieldType::Date, FieldType::DateTime]);
});
