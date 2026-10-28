<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Import\ColumnFormatDetector;
use App\Support\Import\ImportRowMapper;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("field-{$key}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => $key,
        'field_type' => $type,
        'config' => [],
    ]);

    $this->mapper = new ImportRowMapper(
        new ColumnFormatDetector,
        StaticObjectTypeFieldLookup::carrying((string) $this->objectType->getKey(), [
            ($this->field)('name', FieldType::TextShort),
            ($this->field)('founded_on', FieldType::Date),
            ($this->field)('revenue', FieldType::Decimal),
            ($this->field)('contacts', FieldType::RelationHasMany),
        ]),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lifts the identity columns out of the data and leaves them out of the payload', function (): void {
    $values = $this->mapper->map(
        ['Ref' => 'EXT-1', 'Nr' => 'R-7', 'Name' => 'Acme'],
        $this->objectType,
        ['columns' => ['Ref' => 'external_reference_id', 'Nr' => 'record_number', 'Name' => 'name']],
    );

    expect($values->externalReferenceId)->toBe('EXT-1')
        ->and($values->recordNumber)->toBe('R-7')
        ->and($values->data)->toBe(['name' => 'Acme']);
});

it('reads an empty identity cell as no identity at all', function (): void {
    $values = $this->mapper->map(
        ['Ref' => '  ', 'Name' => 'Acme'],
        $this->objectType,
        ['columns' => ['Ref' => 'external_reference_id', 'Name' => 'name']],
    );

    expect($values->externalReferenceId)->toBeNull();
});

it('normalizes a cell by the type of its target field', function (): void {
    $values = $this->mapper->map(
        ['Gruendung' => '01.02.2026', 'Umsatz' => '1.234,50'],
        $this->objectType,
        ['columns' => ['Gruendung' => 'founded_on', 'Umsatz' => 'revenue']],
    );

    expect($values->data)->toBe(['founded_on' => '2026-02-01', 'revenue' => '1234.50']);
});

it('lets a declared per column format win over the type of the target field', function (): void {
    $values = $this->mapper->map(
        ['Umsatz' => '1.234,50'],
        $this->objectType,
        ['columns' => ['Umsatz' => 'revenue'], 'formats' => ['Umsatz' => 'integer']],
    );

    expect($values->data)->toBe(['revenue' => 1235]);
});

it('ignores a declared format that names no known format', function (): void {
    $values = $this->mapper->map(
        ['Gruendung' => '01.02.2026'],
        $this->objectType,
        ['columns' => ['Gruendung' => 'founded_on'], 'formats' => ['Gruendung' => 'nonsense']],
    );

    expect($values->data)->toBe(['founded_on' => '2026-02-01']);
});

it('clears a mapped empty cell to nothing so the field can be emptied on update', function (): void {
    $values = $this->mapper->map(
        ['Name' => ''],
        $this->objectType,
        ['columns' => ['Name' => 'name']],
    );

    expect($values->data)->toBe(['name' => null]);
});

it('keeps an unmapped field out of the payload entirely', function (): void {
    $values = $this->mapper->map(
        ['Name' => 'Acme', 'Umsatz' => '10'],
        $this->objectType,
        ['columns' => ['Name' => 'name']],
    );

    expect($values->data)->toBe(['name' => 'Acme']);
});

it('splits a relation cell into single references and keeps it out of the data payload', function (): void {
    $values = $this->mapper->map(
        ['Kontakte' => 'EXT-1, EXT-2 ,'],
        $this->objectType,
        ['columns' => ['Kontakte' => 'contacts']],
    );

    expect($values->data)->toBe([])
        ->and($values->relations['contacts']['values'])->toBe(['EXT-1', 'EXT-2']);
});

it('reports no relation at all for an empty relation cell', function (): void {
    $values = $this->mapper->map(
        ['Kontakte' => '   '],
        $this->objectType,
        ['columns' => ['Kontakte' => 'contacts']],
    );

    expect($values->relations)->toBe([]);
});

it('drops a column that is mapped to a field the object type does not define', function (): void {
    $values = $this->mapper->map(
        ['Gehalt' => '100'],
        $this->objectType,
        ['columns' => ['Gehalt' => 'salary']],
    );

    expect($values->data)->toBe([]);
});
