<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Import\MissingOptionResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->resolver = new MissingOptionResolver;

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'companies',
        'is_system' => false,
    ]);

    $this->systemObjectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('system-type'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'users',
        'is_system' => true,
    ]);

    $this->selectField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('status-field'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'status',
        'field_type' => FieldType::SingleSelect,
        'config' => ['options' => ['open', 'closed']],
    ]);

    /** @var callable(list<string>):ImportMissingOptionMode */
    $this->effectiveModeFor = function (array $abilities): ImportMissingOptionMode {
        $user = AccessContext::user($this->tenant, [], 'importer');
        AccessContext::actAs($user);
        AccessContext::grant(...$abilities);

        return $this->resolver->effectiveMode(ImportMissingOptionMode::Create, $user, $this->objectType);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('falls back to erroring on unknown options when the caller may not change the object type', function (): void {
    expect(($this->effectiveModeFor)(['companies.import', 'companies.create']))
        ->toBe(ImportMissingOptionMode::Error);
});

it('creates missing options only for a caller who may change the object type', function (): void {
    expect(($this->effectiveModeFor)(['companies.import', 'object-types.update']))
        ->toBe(ImportMissingOptionMode::Create);
});

it('never creates options on a system object type even for a caller with the update permission', function (): void {
    $user = AccessContext::user($this->tenant, [], 'importer');
    AccessContext::actAs($user);
    AccessContext::grant('object-types.update');

    expect($this->resolver->effectiveMode(ImportMissingOptionMode::Create, $user, $this->systemObjectType))
        ->toBe(ImportMissingOptionMode::Error);
});

it('leaves the erroring mode untouched no matter what the caller may do', function (): void {
    $user = AccessContext::user($this->tenant, [], 'importer');
    AccessContext::actAs($user);
    AccessContext::grant('object-types.update');

    expect($this->resolver->effectiveMode(ImportMissingOptionMode::Error, $user, $this->objectType))
        ->toBe(ImportMissingOptionMode::Error);
});

it('reports only the values a single select does not know yet', function (): void {
    expect($this->resolver->missingValues($this->selectField, 'open'))->toBe([])
        ->and($this->resolver->missingValues($this->selectField, 'pending'))->toBe(['pending'])
        ->and($this->resolver->missingValues($this->selectField, ['pending', 'pending', 'open']))->toBe(['pending']);
});

it('ignores a select that draws its options from another object type', function (): void {
    $lookupField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('lookup-field'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'owner',
        'field_type' => FieldType::SingleSelect,
        'config' => ['lookup_object_type' => 'contacts'],
    ]);

    expect($this->resolver->isInlineSelect($lookupField))->toBeFalse()
        ->and($this->resolver->missingValues($lookupField, 'anything'))->toBe([]);
});
