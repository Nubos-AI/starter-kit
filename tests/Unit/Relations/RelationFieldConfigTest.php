<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Handlers\CustomFields\RelationFieldHandler;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(array<string, mixed>|null):FieldDefinition */
    $this->field = fn (?array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('partner-field'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'key' => 'partner',
        'field_type' => FieldType::RelationManyToMany->value,
        'config' => $config,
    ]);

    $this->handler = new RelationFieldHandler;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a relationship field that names no relationship type and looks nothing up', function (): void {
    $attempt = QueryShape::attemptedBy(function (): void {
        try {
            $this->handler->validateConfig(($this->field)([]));
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['config']);
        }
    });

    expect($attempt)->toBeNull();
});

it('refuses a relationship field whose relationship type was cleared to an empty string', function (): void {
    try {
        $this->handler->validateConfig(($this->field)(['relationship_type_id' => '']));
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['config']);
    }
});

it('accepts a relationship type only while it originates from the object type of the field', function (): void {
    $relationshipTypeId = ModelStub::ulid('companies-partners');

    $shape = QueryShape::attemptedBy(fn () => $this->handler->validateConfig(
        ($this->field)(['relationship_type_id' => $relationshipTypeId]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('relationship_types'))->toBeTrue()
        ->and($shape->isKeyedTo('relationship_types', $relationshipTypeId))->toBeTrue()
        ->and($shape->sql)->toContain('"from_object_type_id" = ?')
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('relationship_types', (string) $this->tenant->getKey()))->toBeTrue();
});
