<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Exceptions\CustomFields\UnknownFieldTypeException;
use App\Handlers\CustomFields\FileFieldHandler;
use App\Handlers\CustomFields\RelationFieldHandler;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Exists;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->registry = app(FieldTypeRegistry::class);

    /** @var callable(FieldType, array<string, mixed>):FieldDefinition */
    $this->field = static fn (FieldType $type, array $attributes = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        ['field_type' => $type->value, ...$attributes],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('resolves every field type to a handler that declares the same type', function (): void {
    $relationTypes = [FieldType::RelationHasMany, FieldType::RelationManyToMany];

    foreach (FieldType::cases() as $type) {
        expect($this->registry->hasHandler($type))->toBeTrue();

        if (in_array($type, $relationTypes, true)) {
            expect($this->registry->handlerFor($type))->toBeInstanceOf(RelationFieldHandler::class);

            continue;
        }

        expect($this->registry->handlerFor($type)->fieldType())->toBe($type);
    }
});

it('keeps a relation value out of the jsonb payload column', function (): void {
    $field = ($this->field)(FieldType::RelationManyToMany);

    expect($this->registry->handlerFor(FieldType::RelationManyToMany)->cast(['01J0', '01J1'], $field))->toBeNull();
});

it('names the offending type when no handler is registered for it', function (): void {
    $handlers = config('engine.field_type_handlers');

    expect($handlers)->toBeArray()
        ->and($handlers)->toHaveKey(FieldType::Computed->value);

    unset($handlers[FieldType::Computed->value]);
    config(['engine.field_type_handlers' => $handlers]);

    expect($this->registry->hasHandler(FieldType::Computed))->toBeFalse();

    try {
        $this->registry->handlerFor(FieldType::Computed);
    } catch (UnknownFieldTypeException $exception) {
        expect($exception->fieldType)->toBe(FieldType::Computed)
            ->and($exception->getMessage())->toContain('computed');

        return;
    }

    $this->fail('the registry handed out a handler for an unregistered type');
});

it('registers the computed handler with a nullable rule set that never persists its value', function (): void {
    $field = ($this->field)(FieldType::Computed, ['config' => ['formula' => '1 + 1', 'result_type' => 'number']]);
    $handler = $this->registry->handlerFor(FieldType::Computed);

    expect($handler->fieldType())->toBe(FieldType::Computed)
        ->and($handler->validationRules($field))->toBe(['nullable'])
        ->and($handler->cast('42', $field))->toBeNull();
});

it('accepts a fitting value and refuses an unfitting one for every scalar handler', function (FieldType $type, mixed $valid, mixed $invalid): void {
    $rules = ['value' => $this->registry->handlerFor($type)->validationRules(($this->field)($type))];

    expect(Validator::make(['value' => $valid], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['value' => $invalid], $rules)->errors()->keys())->toBe(['value']);
})->with([
    'short text' => [FieldType::TextShort, 'Acme', str_repeat('a', 256)],
    'long text' => [FieldType::TextLong, str_repeat('a', 5000), ['not', 'text']],
    'number' => [FieldType::Number, 42, '4.2'],
    'decimal' => [FieldType::Decimal, '4.25', 'vier'],
    'money' => [FieldType::Money, '19.99', 'teuer'],
    'date' => [FieldType::Date, '2026-03-04', '04.03.2026'],
    'date time' => [FieldType::DateTime, '2026-03-04 10:11:12', 'gestern'],
    'boolean' => [FieldType::Boolean, true, 'vielleicht'],
    'email' => [FieldType::Email, 'ada@acme.test', 'ada at acme'],
    'url' => [FieldType::Url, 'https://acme.test', 'acme'],
]);

it('constrains a select value to the configured options', function (): void {
    $single = ['value' => $this->registry->handlerFor(FieldType::SingleSelect)->validationRules(
        ($this->field)(FieldType::SingleSelect, ['config' => ['options' => ['open', 'closed']]]),
    )];
    $multiRules = $this->registry->handlerFor(FieldType::MultiSelect)->validationRules(
        ($this->field)(FieldType::MultiSelect, ['config' => ['options' => ['red', 'blue']]]),
    );
    $multi = ['value' => array_values(array_filter($multiRules, is_int(...), ARRAY_FILTER_USE_KEY)), 'value.*' => $multiRules['*']];

    expect(Validator::make(['value' => 'open'], $single)->passes())->toBeTrue()
        ->and(Validator::make(['value' => null], $single)->passes())->toBeTrue()
        ->and(Validator::make(['value' => 'x'], $single)->errors()->keys())->toBe(['value'])
        ->and(Validator::make(['value' => ['red', 'blue']], $multi)->passes())->toBeTrue()
        ->and(Validator::make(['value' => ['red', 'green']], $multi)->errors()->keys())->toBe(['value.1']);
});

it('appends the configured regex to the phone rules', function (): void {
    $rules = $this->registry->handlerFor(FieldType::Phone)->validationRules(
        ($this->field)(FieldType::Phone, ['config' => ['regex' => '/^\+?[0-9]+$/']]),
    );

    expect($rules)->toContain('string')
        ->and($rules)->toContain('regex:/^\+?[0-9]+$/');
});

it('references an attachment id scoped to the bound tenant instead of the bytes', function (): void {
    $single = ($this->field)(FieldType::File);
    $multiple = ($this->field)(FieldType::File, ['config' => ['multiple' => true]]);
    $handler = $this->registry->handlerFor(FieldType::File);

    expect($handler)->toBeInstanceOf(FileFieldHandler::class)
        ->and($handler->cast('01J0', $single))->toBe('01J0')
        ->and($handler->cast(['01J0', '01J1'], $multiple))->toBe(['01J0', '01J1']);

    $tenantId = (string) $this->tenant->getKey();
    $singleRules = $handler->validationRules($single);
    $multipleRules = $handler->validationRules($multiple);

    expect(array_keys($singleRules))->toBe([0, 1])
        ->and($singleRules[0])->toBe('string')
        ->and($singleRules[1])->toBeInstanceOf(Exists::class)
        ->and((string) $singleRules[1])->toStartWith('exists:attachments,id,')
        ->and((string) $singleRules[1])->toContain('tenant_id')
        ->and((string) $singleRules[1])->toContain($tenantId);

    expect(array_keys($multipleRules))->toBe([0, '*'])
        ->and($multipleRules[0])->toBe('array')
        ->and(array_keys($multipleRules['*']))->toBe([0, 1])
        ->and($multipleRules['*'][0])->toBe('string')
        ->and($multipleRules['*'][1])->toBeInstanceOf(Exists::class)
        ->and((string) $multipleRules['*'][1])->toStartWith('exists:attachments,id,')
        ->and((string) $multipleRules['*'][1])->toContain('tenant_id')
        ->and((string) $multipleRules['*'][1])->toContain($tenantId);
});

it('casts a number to an integer and drops non numeric input', function (): void {
    $field = ($this->field)(FieldType::Number);
    $handler = $this->registry->handlerFor(FieldType::Number);

    expect($handler->cast('42', $field))->toBe(42)
        ->and($handler->cast(7.9, $field))->toBe(7)
        ->and($handler->cast('not a number', $field))->toBeNull();
});

it('casts money and decimal to a precise string and never to a float', function (): void {
    $money = $this->registry->handlerFor(FieldType::Money)->cast('99.90', ($this->field)(FieldType::Money));
    $decimal = $this->registry->handlerFor(FieldType::Decimal)->cast('12.50', ($this->field)(FieldType::Decimal));

    expect($money)->toBe('99.90')
        ->and($money)->toBeString()
        ->and($decimal)->toBe('12.50')
        ->and($decimal)->toBeString();
});

it('normalises truthy and falsy boolean input', function (): void {
    $field = ($this->field)(FieldType::Boolean);
    $handler = $this->registry->handlerFor(FieldType::Boolean);

    expect($handler->cast('1', $field))->toBeTrue()
        ->and($handler->cast('0', $field))->toBeFalse()
        ->and($handler->cast('true', $field))->toBeTrue()
        ->and($handler->cast(null, $field))->toBeNull();
});

it('re indexes a multi select value into a list of strings', function (): void {
    $field = ($this->field)(FieldType::MultiSelect);
    $handler = $this->registry->handlerFor(FieldType::MultiSelect);

    expect($handler->cast([3 => 'a', 7 => 2, 9 => ['x']], $field))->toBe(['a', '2', ''])
        ->and($handler->cast(null, $field))->toBeNull();
});

it('normalises geo coordinates to floats and tolerates a missing one', function (): void {
    $field = ($this->field)(FieldType::GeoAddress);
    $handler = $this->registry->handlerFor(FieldType::GeoAddress);

    expect($handler->cast(['lat' => '52.52', 'lng' => '13.405', 'label' => 'Berlin'], $field))
        ->toBe(['lat' => 52.52, 'lng' => 13.405, 'label' => 'Berlin'])
        ->and($handler->cast(['lat' => '52.52', 'label' => 'Berlin'], $field))
        ->toBe(['lat' => 52.52, 'lng' => null, 'label' => 'Berlin']);
});

it('reduces a composite value to a scalar search string', function (): void {
    expect($this->registry->handlerFor(FieldType::MultiSelect)->toSearchable(['a', 'b'], ($this->field)(FieldType::MultiSelect)))->toBe('a, b')
        ->and($this->registry->handlerFor(FieldType::GeoAddress)->toSearchable(['lat' => 1.0, 'lng' => 2.0, 'label' => 'Berlin'], ($this->field)(FieldType::GeoAddress)))->toBe('Berlin')
        ->and($this->registry->handlerFor(FieldType::TextShort)->toSearchable('Hallo', ($this->field)(FieldType::TextShort)))->toBe('Hallo');
});
