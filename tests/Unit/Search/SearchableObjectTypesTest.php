<?php

declare(strict_types=1);

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Search\SearchableObjectTypes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticFieldVisibility;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);

    $this->companies = ModelStub::ulid('companies');
    $this->contacts = ModelStub::ulid('contacts');

    $this->objectTypes = ModelStub::collection(ObjectType::class, [
        ['id' => $this->companies, 'tenant_id' => $this->tenant->getKey(), 'slug' => 'companies'],
        ['id' => $this->contacts, 'tenant_id' => $this->tenant->getKey(), 'slug' => 'contacts'],
    ]);

    /** @var callable(string, string, array<string, mixed>):array<string, mixed> */
    $this->field = fn (string $objectTypeId, string $key, array $flags = []): array => [
        'id' => ModelStub::ulid($objectTypeId.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $objectTypeId,
        'key' => $key,
        'is_searchable' => true,
        'is_encrypted' => false,
        ...$flags,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    StaticFieldVisibility::forget();
});

it('drops an object type the caller may not view even when every field is readable', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([
        $this->companies => ['name'],
        $this->contacts => ['name'],
    ]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->contacts, 'name'),
    ]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields);

    expect(array_keys($searchable))->toBe([$this->companies]);
});

it('drops an object type the extra filter rejects even when the caller may view it', function (): void {
    AccessContext::grant('companies.view', 'contacts.view');
    StaticFieldVisibility::install([
        $this->companies => ['name'],
        $this->contacts => ['name'],
    ]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->contacts, 'name'),
    ]);

    $searchable = SearchableObjectTypes::for(
        $this->user,
        fn (ObjectType $objectType): bool => $objectType->slug === 'contacts',
        $this->objectTypes,
        $fields,
    );

    expect(array_keys($searchable))->toBe([$this->contacts]);
});

it('offers only the fields the caller may read as searchable attributes', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name', 'city']]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->companies, 'city'),
        ($this->field)($this->companies, 'salary'),
    ]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields);

    expect($searchable[$this->companies]['attributes'])->toBe(['name', 'city'])
        ->and($searchable[$this->companies]['isRestricted'])->toBeTrue();
});

it('marks an object type unrestricted only when every searchable field is readable', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name', 'city']]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->companies, 'city'),
    ]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields);

    expect($searchable[$this->companies]['isRestricted'])->toBeFalse();
});

it('drops an object type whose readable fields are none', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => []]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
    ]);

    expect(SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields))->toBe([]);
});

it('never offers a field that is not searchable or that is encrypted', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name', 'secret', 'internal']]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->companies, 'secret', ['is_encrypted' => true]),
        ($this->field)($this->companies, 'internal', ['is_searchable' => false]),
    ]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields);

    expect($searchable[$this->companies]['attributes'])->toBe(['name']);
});

it('never lets a field definition of a foreign object type leak into the attributes', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name', 'salary']]);

    $fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)(ModelStub::ulid('deals'), 'salary'),
    ]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, $fields);

    expect($searchable[$this->companies]['attributes'])->toBe(['name']);
});

it('answers with nothing at all when the caller may view no object type', function (): void {
    $resolver = AccessContext::grant();
    StaticFieldVisibility::install([$this->companies => ['name']]);

    $searchable = SearchableObjectTypes::for($this->user, null, $this->objectTypes, new EloquentCollection);

    expect($searchable)->toBe([])
        ->and($resolver->askedFor)->toBe(['companies.view', 'contacts.view']);
});
