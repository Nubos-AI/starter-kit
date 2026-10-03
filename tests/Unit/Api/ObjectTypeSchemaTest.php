<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Api\ObjectTypeSchemaBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->builder = new ObjectTypeSchemaBuilder;
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $attributes = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_required' => false,
            ...$attributes,
        ],
    );

    /** @var callable(list<FieldDefinition>):array<string, mixed> */
    $this->schemaOf = function (array $fields): array {
        $objectType = ModelStub::make(ObjectType::class, [
            'id' => $this->objectTypeId,
            'tenant_id' => $this->tenant->getKey(),
            'slug' => 'companies',
            'key' => 'companies',
        ], ['fieldDefinitions' => new EloquentCollection($fields)]);

        return $this->builder->component($objectType)->toArray();
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('requires the spine attributes and nothing a tenant defined', function (): void {
    $schema = ($this->schemaOf)([($this->field)('name', FieldType::TextShort)]);

    expect($schema['required'])->toBe(['recordNumber', 'externalReferenceId', 'version', 'data', 'createdAt', 'updatedAt'])
        ->and(array_keys($schema['properties']))->toBe([
            'recordNumber',
            'externalReferenceId',
            'version',
            'data',
            'createdAt',
            'updatedAt',
            'name',
        ]);
});

it('maps a scalar field type onto its openapi counterpart', function (FieldType $type, string $expected, ?string $format): void {
    $schema = ($this->schemaOf)([($this->field)('probe', $type)]);
    $property = $schema['properties']['probe'];

    expect($property['type'])->toBe([$expected, 'null'])
        ->and($property['format'] ?? null)->toBe($format);
})->with([
    'short text' => [FieldType::TextShort, 'string', null],
    'long text' => [FieldType::TextLong, 'string', null],
    'phone' => [FieldType::Phone, 'string', null],
    'email' => [FieldType::Email, 'string', 'email'],
    'url' => [FieldType::Url, 'string', 'uri'],
    'number' => [FieldType::Number, 'integer', null],
    'date' => [FieldType::Date, 'string', 'date'],
    'date time' => [FieldType::DateTime, 'string', 'date-time'],
    'boolean' => [FieldType::Boolean, 'boolean', null],
]);

it('documents a derived field as read only', function (FieldType $type): void {
    $schema = ($this->schemaOf)([($this->field)('derived', $type)]);

    expect($schema['properties']['derived']['readOnly'] ?? null)->toBeTrue();
})->with([FieldType::Computed, FieldType::Rollup]);

it('keeps a writable field out of the read only set', function (): void {
    $schema = ($this->schemaOf)([($this->field)('name', FieldType::TextShort)]);

    expect($schema['properties']['name']['readOnly'] ?? null)->toBeNull();
});

it('documents money and decimal as either a number or a string so the client may send both', function (FieldType $type): void {
    $schema = ($this->schemaOf)([($this->field)('amount', $type)]);

    expect($schema['properties']['amount']['type'])->toBe(['number', 'string', 'null']);
})->with([FieldType::Decimal, FieldType::Money]);

it('carries the options of a select field as an enum and drops a non string option', function (): void {
    $schema = ($this->schemaOf)([
        ($this->field)('status', FieldType::SingleSelect, ['config' => ['options' => ['open', 'won', 42]]]),
    ]);

    expect($schema['properties']['status']['enum'])->toBe(['open', 'won', null]);
});

it('leaves a select field without options a plain string and carries no empty enum', function (): void {
    $schema = ($this->schemaOf)([($this->field)('status', FieldType::SingleSelect, ['config' => []])]);

    expect($schema['properties']['status'])->not->toHaveKey('enum')
        ->and($schema['properties']['status']['type'])->toBe(['string', 'null']);
});

it('documents a required field as not nullable', function (): void {
    $schema = ($this->schemaOf)([($this->field)('name', FieldType::TextShort, ['is_required' => true])]);

    expect($schema['properties']['name']['type'])->toBe('string');
});

it('documents a multi file field as a list and a single file field as a string', function (bool $multiple, string $expected): void {
    $schema = ($this->schemaOf)([
        ($this->field)('attachment', FieldType::File, ['config' => ['multiple' => $multiple]]),
    ]);

    expect($schema['properties']['attachment']['type'])->toBe([$expected, 'null']);
})->with([
    'many' => [true, 'array'],
    'one' => [false, 'string'],
]);

it('documents a relation field as a list of identifiers', function (FieldType $type): void {
    $schema = ($this->schemaOf)([($this->field)('links', $type)]);

    expect($schema['properties']['links']['type'])->toBe(['array', 'null'])
        ->and($schema['properties']['links']['items']['type'])->toBe('string');
})->with([FieldType::RelationHasMany, FieldType::RelationManyToMany]);

it('stays a valid component for an object type without a single field', function (): void {
    $schema = ($this->schemaOf)([]);

    expect(array_keys($schema['properties']))->toBe($schema['required']);
});
