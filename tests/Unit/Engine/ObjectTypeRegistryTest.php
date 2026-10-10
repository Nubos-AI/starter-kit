<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordRelationDescriptor;
use App\Enums\Engine\RelationCardinality;
use App\Enums\Engine\RelationDirection;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->registry = new ObjectTypeRegistry;

    $this->companies = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'company',
        'slug' => 'companies',
    ]);

    $this->contacts = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('contacts'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'contact',
        'slug' => 'contacts',
    ]);

    /** @var callable(string, string, string, string):RelationshipType */
    $this->relationshipType = fn (
        string $key,
        string $inverseKey,
        string $fromId,
        string $toId,
    ): RelationshipType => ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('relationship-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => $fromId,
        'to_object_type_id' => $toId,
        'key' => $key,
        'inverse_key' => $inverseKey,
        'cardinality' => RelationCardinality::OneToMany,
        'is_hierarchy' => false,
    ]);

    /** @var callable(list<RelationshipType>):ObjectTypeRegistry */
    $this->registryWith = function (array $types): ObjectTypeRegistry {
        /** @var ObjectTypeRegistry $registry */
        $registry = Mockery::mock(ObjectTypeRegistry::class)->makePartial();
        $registry->shouldReceive('relationshipTypes')->andReturn(new EloquentCollection($types));

        return $registry;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks an object type up by slug inside the tenant', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): ObjectType => $this->registry->bySlug('companies'));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->hasBinding('companies'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});

it('reads the field definitions of an object type in list order', function (): void {
    $objectTypeId = (string) $this->companies->getKey();

    $attempt = QueryShape::attemptedBy(fn (): mixed => $this->registry->fields($objectTypeId));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('field_definitions'))->toBeTrue()
        ->and($attempt?->hasBinding($objectTypeId))->toBeTrue()
        ->and($attempt?->sql)->toContain('order by "list_position" asc, "created_at" asc');
});

it('asks for both endpoints when it collects the relationship types of an object type', function (): void {
    $objectTypeId = (string) $this->companies->getKey();

    $attempt = QueryShape::attemptedBy(fn (): mixed => $this->registry->relationshipTypes($objectTypeId));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('relationship_types'))->toBeTrue()
        ->and($attempt?->hasColumnCondition('relationship_types', 'deleted_at'))->toBeFalse()
        ->and($attempt?->hidesSoftDeleted('relationship_types'))->toBeTrue()
        ->and($attempt?->bindings)->toBe([$objectTypeId, $objectTypeId, (string) $this->tenant->getKey()]);
});

it('reports a missing object type as not found rather than as null', function (): void {
    /** @var ObjectTypeRegistry $registry */
    $registry = Mockery::mock(ObjectTypeRegistry::class)->makePartial();
    $registry->shouldReceive('find')->andReturn(null);

    expect(fn (): ObjectType => $registry->byId(ModelStub::ulid('nothing-here')))
        ->toThrow(ModelNotFoundException::class);
});

it('names an outgoing relation after the key and an incoming one after the inverse key', function (): void {
    $type = ($this->relationshipType)(
        'employs-staff',
        'works-for',
        (string) $this->companies->getKey(),
        (string) $this->contacts->getKey(),
    );

    $registry = ($this->registryWith)([$type]);

    $outgoing = $registry->relationDescriptors((string) $this->companies->getKey())['employsStaff'];
    $incoming = $registry->relationDescriptors((string) $this->contacts->getKey())['worksFor'];

    expect($outgoing)->toBeInstanceOf(RecordRelationDescriptor::class)
        ->and($outgoing->direction)->toBe(RelationDirection::Outgoing)
        ->and($outgoing->counterpartObjectTypeId)->toBe((string) $this->contacts->getKey())
        ->and($outgoing->relationshipTypeId)->toBe((string) $type->getKey())
        ->and($incoming->direction)->toBe(RelationDirection::Incoming)
        ->and($incoming->counterpartObjectTypeId)->toBe((string) $this->companies->getKey());
});

it('gives a self referencing relationship type both of its descriptors', function (): void {
    $departments = (string) $this->companies->getKey();

    $registry = ($this->registryWith)([
        ($this->relationshipType)('departments-parent', 'departments-child', $departments, $departments),
    ]);

    $descriptors = $registry->relationDescriptors($departments);

    expect($descriptors)->toHaveKeys(['departmentsParent', 'departmentsChild'])
        ->and($descriptors['departmentsParent']->direction)->toBe(RelationDirection::Outgoing)
        ->and($descriptors['departmentsChild']->direction)->toBe(RelationDirection::Incoming);
});

it('describes no relation at all for an object type that is no endpoint of one', function (): void {
    $registry = ($this->registryWith)([]);

    expect($registry->relationDescriptors((string) $this->companies->getKey()))->toBe([]);
});

it('carries the cardinality and the hierarchy flag of the relationship type into the descriptor', function (): void {
    $type = ($this->relationshipType)(
        'departments-parent',
        'departments-child',
        (string) $this->companies->getKey(),
        (string) $this->contacts->getKey(),
    );
    $type->setAttribute('is_hierarchy', true);
    $type->setAttribute('cardinality', RelationCardinality::ManyToMany);

    $descriptor = ($this->registryWith)([$type])
        ->relationDescriptors((string) $this->companies->getKey())['departmentsParent'];

    expect($descriptor->isHierarchy)->toBeTrue()
        ->and($descriptor->cardinality)->toBe(RelationCardinality::ManyToMany);
});
