<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\RecordValidator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->validator = app(RecordValidator::class);

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = static fn (string $key, FieldType $type, array $attributes = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        ['key' => $key, 'field_type' => $type->value, ...$attributes],
        [],
    );

    /** @var callable(list<FieldDefinition>):ObjectType */
    $this->typeWith = fn (array $fields): ObjectType => ModelStub::make(
        ObjectType::class,
        ['tenant_id' => $this->tenant->getKey()],
        ['fieldDefinitions' => new EloquentCollection($fields)],
    );

    /** @var callable(ObjectType, array<string, mixed>|null):void */
    $this->validate = fn (ObjectType $type, ?array $data): mixed => $this->validator->validate(
        $type,
        $data,
        (string) $this->tenant->getKey(),
    );

    /** @var callable(ObjectType, array<string, mixed>|null):array<string, list<string>> */
    $this->errorsOf = function (ObjectType $type, ?array $data): array {
        try {
            ($this->validate)($type, $data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the validator accepted data it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects a missing required field and lets an optional one be omitted', function (): void {
    $required = ($this->typeWith)([($this->field)('code', FieldType::TextShort, ['is_required' => true])]);
    $optional = ($this->typeWith)([($this->field)('note', FieldType::TextShort)]);

    expect(($this->errorsOf)($required, []))->toHaveKey('data.code')
        ->and(($this->validate)($required, ['code' => 'ABC-1']))->toBeNull()
        ->and(($this->validate)($optional, []))->toBeNull();
});

it('enforces a configured regex format rule', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('slug', FieldType::TextShort, ['validation_rules' => ['regex' => '/^[a-z]+$/']]),
    ]);

    expect(($this->errorsOf)($type, ['slug' => 'NOT-lower']))->toHaveKey('data.slug')
        ->and(($this->validate)($type, ['slug' => 'abc']))->toBeNull();
});

it('enforces a numeric range from the field definition', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('qty', FieldType::Number, ['validation_rules' => ['min' => 1, 'max' => 10]]),
    ]);

    expect(($this->errorsOf)($type, ['qty' => 0]))->toHaveKey('data.qty')
        ->and(($this->errorsOf)($type, ['qty' => 25]))->toHaveKey('data.qty')
        ->and(($this->validate)($type, ['qty' => 5]))->toBeNull();
});

it('turns a literal date bound into a date comparison rule', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('due', FieldType::Date, ['validation_rules' => ['min' => '2026-01-01', 'max' => '2026-12-31']]),
    ]);

    expect(($this->errorsOf)($type, ['due' => '2025-12-31']))->toHaveKey('data.due')
        ->and(($this->validate)($type, ['due' => '2026-06-15']))->toBeNull();
});

it('enforces a date cross field rule against the referenced field', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('start_date', FieldType::Date),
        ($this->field)('end_date', FieldType::Date, ['validation_rules' => ['cross' => ['after_or_equal' => 'start_date']]]),
    ]);

    expect(($this->errorsOf)($type, ['start_date' => '2026-06-10', 'end_date' => '2026-06-05']))->toHaveKey('data.end_date')
        ->and(($this->validate)($type, ['start_date' => '2026-06-10', 'end_date' => '2026-06-20']))->toBeNull();
});

it('skips a cross field rule when the referenced field is absent', function (): void {
    $dates = ($this->typeWith)([
        ($this->field)('start_date', FieldType::Date),
        ($this->field)('end_date', FieldType::Date, ['validation_rules' => ['cross' => ['after_or_equal' => 'start_date']]]),
    ]);

    $numbers = ($this->typeWith)([
        ($this->field)('min_qty', FieldType::Number),
        ($this->field)('max_qty', FieldType::Number, ['validation_rules' => ['cross' => ['gte' => 'min_qty']]]),
    ]);

    expect(($this->validate)($dates, ['end_date' => '2026-06-20']))->toBeNull()
        ->and(($this->validate)($numbers, ['max_qty' => 5]))->toBeNull();
});

it('enforces a numeric cross field rule once both values are present', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('min_qty', FieldType::Number),
        ($this->field)('max_qty', FieldType::Number, ['validation_rules' => ['cross' => ['gte' => 'min_qty']]]),
    ]);

    expect(($this->errorsOf)($type, ['min_qty' => 10, 'max_qty' => 5]))->toHaveKey('data.max_qty')
        ->and(($this->validate)($type, ['min_qty' => 3, 'max_qty' => 8]))->toBeNull();
});

it('constrains a value to a configured allow list', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('priority', FieldType::TextShort, ['validation_rules' => ['in' => ['low', 'high']]]),
    ]);

    expect(($this->errorsOf)($type, ['priority' => 'medium']))->toHaveKey('data.priority')
        ->and(($this->validate)($type, ['priority' => 'high']))->toBeNull();
});

it('ignores an unknown validation rule key instead of running it as a raw rule', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('free', FieldType::TextShort, [
            'validation_rules' => ['exists' => 'users,id', 'totally_made_up' => true],
        ]),
    ]);

    expect(($this->validate)($type, ['free' => 'anything']))->toBeNull();
});

it('skips a broken or oversized regex rather than breaking the whole validation', function (): void {
    $broken = ($this->typeWith)([
        ($this->field)('code', FieldType::TextShort, ['validation_rules' => ['regex' => '/(unclosed/']]),
    ]);
    $oversized = ($this->typeWith)([
        ($this->field)('code', FieldType::TextShort, ['validation_rules' => ['regex' => '/'.str_repeat('a', 600).'/']]),
    ]);

    expect(($this->validate)($broken, ['code' => 'anything']))->toBeNull()
        ->and(($this->validate)($oversized, ['code' => 'anything']))->toBeNull();
});

it('tolerates a data key that has no field definition', function (): void {
    $type = ($this->typeWith)([($this->field)('code', FieldType::TextShort, ['is_required' => true])]);

    expect(($this->validate)($type, ['code' => 'X', 'import_ref' => 'legacy-42']))->toBeNull();
});

it('rejects a multi select item outside the configured options', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('tags', FieldType::MultiSelect, ['config' => ['options' => ['a', 'b', 'c']]]),
    ]);

    expect(($this->errorsOf)($type, ['tags' => ['a', 'x']]))->toHaveKey('data.tags.1')
        ->and(($this->validate)($type, ['tags' => ['a', 'b']]))->toBeNull();
});

it('rejects a geo coordinate outside the valid latitude range', function (): void {
    $type = ($this->typeWith)([($this->field)('standort', FieldType::GeoAddress)]);

    expect(($this->errorsOf)($type, ['standort' => ['lat' => 999, 'lng' => 10]]))->toHaveKey('data.standort.lat')
        ->and(($this->validate)($type, ['standort' => ['lat' => 52.5, 'lng' => 13.4]]))->toBeNull();
});

it('names the field by its translated label so the message reaches the user in their language', function (): void {
    $type = ($this->typeWith)([
        ($this->field)('code', FieldType::TextShort, [
            'is_required' => true,
            'i18n_labels' => ['de' => 'Vertragsnummer'],
        ]),
    ]);

    app()->setLocale('de');

    expect(($this->errorsOf)($type, [])['data.code'][0])->toContain('Vertragsnummer');
});

it('validates nothing at all for an object type without field definitions', function (): void {
    expect(($this->validate)(($this->typeWith)([]), ['anything' => 'goes']))->toBeNull();
});
