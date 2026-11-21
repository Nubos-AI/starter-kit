<?php

declare(strict_types=1);

use App\DTOs\Import\ImportRecordMatch;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Import\ImportRecordLocator;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    AccessContext::suspendRowAccess();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
        'dedup_keys' => [],
    ]);

    /** @var callable(string, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, array $attributes = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("field-{$key}"),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => $key,
        'field_type' => FieldType::TextShort,
        'is_unique' => false,
        'is_encrypted' => false,
        'is_translatable' => false,
        ...$attributes,
    ]);

    /** @var callable(list<FieldDefinition>):ImportRecordLocator */
    $this->locatorWith = fn (array $fields): ImportRecordLocator => new ImportRecordLocator(
        app(FieldTypeRegistry::class),
        StaticObjectTypeFieldLookup::carrying((string) $this->objectType->getKey(), $fields),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks a record up by its external reference inside the tenant and the object type', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?ImportRecordMatch => ($this->locatorWith)([])->locate(
        $this->objectType,
        'EXT-1',
        null,
        [],
        $this->tenantId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->hasBinding('EXT-1'))->toBeTrue()
        ->and($shape->sql)->toContain('"external_reference_id" = ?')
        ->and($shape->sql)->toContain('"deleted_at" is null');
});

it('prefers the external reference over the record number when both are given', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?ImportRecordMatch => ($this->locatorWith)([])->locate(
        $this->objectType,
        'EXT-1',
        'R-7',
        [],
        $this->tenantId,
    ));

    expect($shape?->hasBinding('EXT-1'))->toBeTrue()
        ->and($shape?->hasBinding('R-7'))->toBeFalse();
});

it('falls back to the record number when no external reference is given', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?ImportRecordMatch => ($this->locatorWith)([])->locate(
        $this->objectType,
        '',
        'R-7',
        [],
        $this->tenantId,
    ));

    expect($shape?->sql)->toContain('"record_number" = ?')
        ->and($shape?->hasBinding('R-7'))->toBeTrue();
});

it('counts a field as a duplicate key only when it is unique, readable in clear text and not translatable', function (): void {
    $fields = [
        ($this->field)('plain'),
        ($this->field)('vat_id', ['is_unique' => true]),
        ($this->field)('secret', ['is_unique' => true, 'is_encrypted' => true]),
        ($this->field)('name', ['is_unique' => true, 'is_translatable' => true]),
    ];

    $locator = ($this->locatorWith)($fields);

    expect($locator->uniqueFields($this->objectType)->pluck('key')->all())->toBe(['vat_id'])
        ->and($locator->detectsDuplicates($this->objectType))->toBeTrue();
});

it('reports that duplicates cannot be detected without a unique field and without dedup keys', function (): void {
    expect(($this->locatorWith)([($this->field)('plain')])->detectsDuplicates($this->objectType))->toBeFalse();
});

it('detects duplicates through the dedup keys of the object type alone', function (): void {
    $this->objectType->dedup_keys = ['plain'];

    expect(($this->locatorWith)([($this->field)('plain')])->detectsDuplicates($this->objectType))->toBeTrue();
});
