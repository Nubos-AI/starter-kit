<?php

declare(strict_types=1);

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Import\ImportMappingValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'importer'));

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(string):FieldDefinition */
    $this->field = fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("field-{$key}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => $key,
    ]);

    /** @var callable(list<string>):ImportMappingValidator */
    $this->validatorSeeing = fn (array $forbiddenWrite = []): ImportMappingValidator => tap(
        new ImportMappingValidator(StaticObjectTypeFieldLookup::carrying(
            (string) $this->objectType->getKey(),
            [($this->field)('name'), ($this->field)('city')],
        )),
        static fn (): FakeFieldVisibilityResolver => new FakeFieldVisibilityResolver([], $forbiddenWrite),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts a mapping that names only fields the object type defines', function (): void {
    ($this->validatorSeeing)()->validate($this->objectType, $this->user, [
        'columns' => ['Name' => 'name', 'Stadt' => 'city'],
    ]);
})->throwsNoExceptions();

it('accepts the external reference id as a mapping target although it is no field definition', function (): void {
    ($this->validatorSeeing)()->validate($this->objectType, $this->user, [
        'columns' => ['Ref' => 'external_reference_id'],
    ]);
})->throwsNoExceptions();

it('refuses a mapping target the object type does not define', function (): void {
    expect(fn (): mixed => ($this->validatorSeeing)()->validate($this->objectType, $this->user, [
        'columns' => ['Gehalt' => 'salary'],
    ]))->toThrow(ValidationException::class);
});

it('refuses a mapping target the caller may not write', function (): void {
    expect(fn (): mixed => ($this->validatorSeeing)(['city'])->validate($this->objectType, $this->user, [
        'columns' => ['Stadt' => 'city'],
    ]))->toThrow(ValidationException::class);
});

it('ignores columns that are mapped to nothing', function (): void {
    ($this->validatorSeeing)()->validate($this->objectType, $this->user, [
        'columns' => ['Name' => 'name', 'Leer' => '', 'Unbelegt' => null],
    ]);
})->throwsNoExceptions();
