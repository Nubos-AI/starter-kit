<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\FieldIndexingRules;
use App\Support\Engine\SelectFieldConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('field-management-object-type');
    $this->validator = new SelectFieldConfigValidator;

    /** @var callable(array<string, mixed>, FieldType):FieldDefinition */
    $this->field = fn (array $config, FieldType $type = FieldType::SingleSelect): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-management-field'),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'key' => 'priority',
            'field_type' => $type,
            'config' => $config,
        ],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts a selection field that carries its own options', function (): void {
    expect($this->validator->validate(($this->field)(['options' => ['low', 'mid', 'high']])))
        ->toBe(['low', 'mid', 'high']);
});

it('refuses options and a master list lookup side by side', function (): void {
    expect(fn (): array => $this->validator->validate(($this->field)([
        'options' => ['low'],
        'lookup_object_type' => 'companies',
    ])))->toThrow(ValidationException::class);
});

it('leaves the catalogue to the master list when a lookup is configured', function (): void {
    expect($this->validator->validate(($this->field)(['lookup_object_type' => 'companies'])))->toBe([]);
});

it('refuses a selection field without a single usable option', function (array $config): void {
    expect(fn (): array => $this->validator->validate(($this->field)($config)))
        ->toThrow(ValidationException::class);
})->with([
    'no options key at all' => [[]],
    'an empty list' => [['options' => []]],
    'a blank label' => [['options' => ['low', '   ']]],
    'a value that is no label' => [['options' => ['low', 7]]],
    'a duplicate' => [['options' => ['low', 'low']]],
]);

it('lets a new option join an existing selection field without asking about records', function (): void {
    $field = ($this->field)(['options' => ['low', 'mid', 'high', 'urgent']]);

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->validator->validate(
        $field,
        ['options' => ['low', 'mid', 'high']],
    ));

    expect($shape)->toBeNull();
});

it('counts the records of its own object type before it lets an option go', function (): void {
    $field = ($this->field)(['options' => ['low', 'mid']]);

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->validator->validate(
        $field,
        ['options' => ['low', 'mid', 'high']],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue();
});

it('looks inside the list of a multi select before it lets an option go', function (): void {
    $field = ($this->field)(['options' => ['a', 'c']], FieldType::MultiSelect);

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->validator->validate(
        $field,
        ['options' => ['a', 'b', 'c']],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('("data"->\'priority\')::jsonb @> ?')
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->hasBinding('"b"'))->toBeTrue();
});

it('refuses to sort filter or unique index an encrypted field', function (bool $sortable, bool $filterable, bool $unique): void {
    expect(fn (): mixed => (new FieldIndexingRules)->assertEncryptionIsCompatible(true, $sortable, $filterable, $unique))
        ->toThrow(ValidationException::class);
})->with([
    'sortable' => [true, false, false],
    'filterable' => [false, true, false],
    'unique' => [false, false, true],
]);

it('lets an encrypted field pass while it carries none of the three flags', function (): void {
    (new FieldIndexingRules)->assertEncryptionIsCompatible(true, false, false, false);
    (new FieldIndexingRules)->assertEncryptionIsCompatible(false, true, true, true);
})->throwsNoExceptions();

it('guards every field definition endpoint with the object type update permission', function (string $route): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware('permission:object-types.update'))->toBeTrue()
        ->and(RouteShape::named($route)->hasDeclaredMiddleware('capability:custom_fields'))->toBeTrue();
})->with([
    'engine.object-types.fields.store',
    'engine.object-types.fields.update',
    'engine.object-types.fields.destroy',
    'engine.object-types.fields.recompute',
    'engine.object-types.field-groups.store',
    'engine.object-types.field-groups.update',
    'engine.object-types.field-groups.destroy',
]);

it('reaches a field only through its own object type and opens the editor to a viewer', function (): void {
    expect(RouteShape::named('engine.object-types.fields.update')->uri())
        ->toEndWith('engine/object-types/{objectType}/fields/{field}')
        ->and(RouteShape::named('engine.object-types.edit.fields')->hasDeclaredMiddleware('permission:object-types.view'))
        ->toBeTrue()
        ->and(RouteShape::named('engine.object-types.edit.fields')->hasDeclaredMiddleware('permission:object-types.update'))
        ->toBeFalse();
});
