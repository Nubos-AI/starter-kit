<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\RecordValidator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->validator = app(RecordValidator::class);
    $this->objectTypeId = ModelStub::ulid('unique-object-type');

    /** @var callable(array<string, mixed>):ObjectType */
    $this->typeWithUnique = fn (array $attributes = []): ObjectType => ModelStub::make(
        ObjectType::class,
        ['id' => $this->objectTypeId, 'tenant_id' => $this->tenant->getKey()],
        ['fieldDefinitions' => new EloquentCollection([
            ModelStub::make(FieldDefinition::class, [
                'object_type_id' => $this->objectTypeId,
                'key' => 'code',
                'field_type' => FieldType::TextShort->value,
                'is_unique' => true,
                ...$attributes,
            ]),
        ])],
    );

    /** @var callable(ObjectType, ?CustomRecord):?QueryShape */
    $this->uniqueQueryOf = fn (ObjectType $type, ?CustomRecord $ignore = null): ?QueryShape => QueryShape::attemptedBy(
        fn (): mixed => $this->validator->validate($type, ['code' => 'ACME'], (string) $this->tenant->getKey(), $ignore),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks for a duplicate only inside the tenant and the object type, and only among live rows', function (): void {
    $shape = ($this->uniqueQueryOf)(($this->typeWithUnique)());

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('"data"->>\'code\'')
        ->and($shape->hasBinding('ACME'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"object_type_id" = ?')
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->sql)->toContain('"deleted_at" is null');
});

it('excludes the record itself when an existing record is being updated', function (): void {
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('self')]);

    $shape = ($this->uniqueQueryOf)(($this->typeWithUnique)(), $record);

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"id" <> ?')
        ->and($shape->hasBinding((string) $record->getKey()))->toBeTrue();
});

it('never runs a uniqueness lookup for an encrypted or translatable field', function (): void {
    expect(($this->uniqueQueryOf)(($this->typeWithUnique)(['is_encrypted' => true])))->toBeNull()
        ->and(($this->uniqueQueryOf)(($this->typeWithUnique)(['is_translatable' => true])))->toBeNull();
});

it('never runs a uniqueness lookup for a field that is not marked unique', function (): void {
    expect(($this->uniqueQueryOf)(($this->typeWithUnique)(['is_unique' => false])))->toBeNull();
});
