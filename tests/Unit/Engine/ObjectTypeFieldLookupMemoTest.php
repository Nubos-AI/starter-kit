<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\ObjectTypeFieldLookup;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('lookup-tenant');
    $this->objectTypeId = ModelStub::ulid('lookup-object-type');
    $this->otherObjectTypeId = ModelStub::ulid('lookup-other-object-type');
    $this->relationshipTypeId = ModelStub::ulid('lookup-relationship-type');

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "relationship_types"') => [[
                'id' => $this->relationshipTypeId,
                'tenant_id' => (string) $this->tenant->getKey(),
                'from_object_type_id' => $this->otherObjectTypeId,
                'to_object_type_id' => $this->objectTypeId,
            ]],
            str_contains($sql, 'from "object_types"') => [[
                'id' => $this->objectTypeId,
                'tenant_id' => (string) $this->tenant->getKey(),
                'slug' => 'deals',
            ]],
            str_contains($sql, 'from "field_definitions"') => [[
                'id' => ModelStub::ulid('lookup-field'),
                'tenant_id' => (string) $this->tenant->getKey(),
                'object_type_id' => $bindings[0],
                'key' => 'amount',
                'field_type' => FieldType::Number->value,
            ]],
            default => [],
        },
    );

    $this->readsOf = fn (string $table): int => count($this->connection->sqlOf($table));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('hands every class of one request the same lookup and a fresh one to the next request', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);

    expect(app(ObjectTypeFieldLookup::class))->toBe($lookup);

    app()->forgetScopedInstances();

    expect(app(ObjectTypeFieldLookup::class))->not->toBe($lookup);
});

it('reads the fields, the object type and the relationship type of one id only once per request', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);

    foreach (range(1, 3) as $ignored) {
        $lookup->fields($this->objectTypeId);
        $lookup->filterableFields($this->objectTypeId);
        $lookup->computedFields($this->objectTypeId);
        $lookup->objectTypeOrFail($this->objectTypeId);
        $lookup->relationshipTarget($this->relationshipTypeId);
        $lookup->relationship($this->relationshipTypeId);
    }

    expect(($this->readsOf)('field_definitions'))->toBe(1)
        ->and(($this->readsOf)('object_types'))->toBe(1)
        ->and(($this->readsOf)('relationship_types'))->toBe(1)
        ->and($lookup->relationshipTarget($this->relationshipTypeId))->toBe($this->objectTypeId);
});

it('keeps the fields of each object type apart', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);

    $lookup->fields($this->objectTypeId);

    expect($lookup->fields($this->otherObjectTypeId)->first()?->object_type_id)->toBe($this->otherObjectTypeId)
        ->and(($this->readsOf)('field_definitions'))->toBe(2);
});

it('reads the fields again once a field of that object type was saved', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);
    $lookup->fields($this->objectTypeId);
    $lookup->fields($this->otherObjectTypeId);

    event('eloquent.saved: '.FieldDefinition::class, ModelStub::make(FieldDefinition::class, [
        'object_type_id' => $this->objectTypeId,
        'key' => 'amount',
    ]));

    $lookup->fields($this->objectTypeId);
    $lookup->fields($this->otherObjectTypeId);

    expect(($this->readsOf)('field_definitions'))->toBe(3);
});

it('reads the relationship type again once a relationship type was saved', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);
    $lookup->relationshipTarget($this->relationshipTypeId);

    event('eloquent.saved: '.RelationshipType::class, ModelStub::make(RelationshipType::class, [
        'id' => $this->relationshipTypeId,
        'from_object_type_id' => $this->otherObjectTypeId,
        'to_object_type_id' => $this->objectTypeId,
    ]));

    $lookup->relationshipTarget($this->relationshipTypeId);

    expect(($this->readsOf)('relationship_types'))->toBe(2);
});

it('reads the object type again once it was saved', function (): void {
    $lookup = app(ObjectTypeFieldLookup::class);
    $lookup->objectTypeOrFail($this->objectTypeId);

    event('eloquent.saved: '.ObjectType::class, ModelStub::make(ObjectType::class, ['id' => $this->objectTypeId]));

    $lookup->objectTypeOrFail($this->objectTypeId);

    expect(($this->readsOf)('object_types'))->toBe(2);
});
