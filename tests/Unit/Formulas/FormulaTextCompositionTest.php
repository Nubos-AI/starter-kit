<?php

declare(strict_types=1);

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaErrorCode;
use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeFieldLookup;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('text-composition-object-type');

    /** @var callable(string, FieldType, ?array<string, mixed>):FieldDefinition */
    $this->field = function (string $key, FieldType $type, ?array $config = null): FieldDefinition {
        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('text-composition-field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => false,
            'config' => $config,
        ]);
    };

    /** @var callable(string, string, array<string, mixed>):mixed */
    $this->compute = function (string $formula, string $resultType, array $data): mixed {
        $result = ($this->field)('result', FieldType::Computed, [
            'formula' => $formula,
            'result_type' => $resultType,
        ]);

        app()->instance(ObjectTypeFieldLookup::class, StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [
            ($this->field)('first_name', FieldType::TextShort),
            ($this->field)('last_name', FieldType::TextShort),
            ($this->field)('netto', FieldType::Number),
            $result,
        ]));

        $record = ModelStub::make(CustomRecord::class, [
            'id' => ModelStub::ulid('text-composition-record'),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'data' => $data,
        ]);

        return app(ComputedFieldHandler::class)->compute($result, $record);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('treats a blank text field as an empty string', function (): void {
    $value = ($this->compute)(
        'CONCAT({first_name}; " "; {last_name})',
        'text',
        ['first_name' => 'Max', 'last_name' => null],
    );

    expect($value)->toBe('Max ');
});

it('concatenates text with the ampersand operator', function (): void {
    $value = ($this->compute)(
        '{first_name} & " " & {last_name}',
        'text',
        ['first_name' => 'Max', 'last_name' => 'Mustermann'],
    );

    expect($value)->toBe('Max Mustermann');
});

it('widens a number into an ampersand concatenation', function (): void {
    $value = ($this->compute)(
        '"Netto: " & {netto}',
        'text',
        ['netto' => 100],
    );

    expect($value)->toBe('Netto: 100');
});

it('trims the surrounding whitespace of a concatenation', function (): void {
    $value = ($this->compute)(
        'TRIM({first_name} & " " & {last_name})',
        'text',
        ['first_name' => 'Max', 'last_name' => null],
    );

    expect($value)->toBe('Max');
});

it('keeps a blank number field an evaluation error', function (): void {
    $value = ($this->compute)('{netto} * 2', 'number', ['netto' => null]);

    expect($value)->toBeInstanceOf(FormulaErrorValue::class)
        ->and($value->code)->toBe(FormulaErrorCode::NotANumber);
});
