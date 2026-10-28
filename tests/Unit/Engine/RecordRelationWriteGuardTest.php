<?php

declare(strict_types=1);

use App\Actions\Engine\LinkRecordRelationAction;
use App\DTOs\Engine\RecordRelationDescriptor;
use App\Enums\Engine\RelationCardinality;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordRelationResolver;
use App\Support\Engine\Relations\RecordRelation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->companies = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->contacts = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('contacts'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'contacts',
    ]);

    $this->relationshipType = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('employs-staff'),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => $this->companies->getKey(),
        'to_object_type_id' => $this->contacts->getKey(),
        'key' => 'employs-staff',
        'inverse_key' => 'works-for',
        'cardinality' => RelationCardinality::OneToMany,
        'is_hierarchy' => false,
    ]);

    $this->company = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->companies->getKey(),
    ], ['objectType' => $this->companies]);

    /** @var callable(array<string, ObjectType>):RecordRelationResolver */
    $this->resolverKnowing = function (array $objectTypes): RecordRelationResolver {
        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('find')
            ->andReturnUsing(static fn (string $id): ?ObjectType => $objectTypes[$id] ?? null);

        return new RecordRelationResolver($registry);
    };

    /** @var callable():RecordRelation */
    $this->relation = function (): RecordRelation {
        $endpoints = Mockery::mock(RecordEndpointResolver::class);
        $endpoints->shouldReceive('modelClassFor')->andReturn(CustomRecord::class);
        app()->instance(RecordEndpointResolver::class, $endpoints);

        return $this->company->newRecordRelation(new RecordRelationDescriptor(
            name: 'employsStaff',
            relationshipTypeId: (string) $this->relationshipType->getKey(),
            direction: RelationDirection::Outgoing,
            cardinality: RelationCardinality::OneToMany,
            isHierarchy: false,
            counterpartObjectTypeId: (string) $this->contacts->getKey(),
        ));
    };

    /** @var callable(string ...):User */
    $this->actor = function (string ...$abilities): User {
        AccessContext::grant(...$abilities);

        return AccessContext::user($this->tenant);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(RecordEndpointResolver::class);
});

it('names the counterpart of a relation by the direction it is walked in', function (): void {
    $resolver = ($this->resolverKnowing)([]);

    expect($resolver->counterpartObjectTypeId($this->relationshipType, RelationDirection::Outgoing))
        ->toBe((string) $this->contacts->getKey())
        ->and($resolver->counterpartObjectTypeId($this->relationshipType, RelationDirection::Incoming))
        ->toBe((string) $this->companies->getKey());
});

it('refuses to link a record of an object type the actor may not view', function (): void {
    $resolver = ($this->resolverKnowing)([(string) $this->contacts->getKey() => $this->contacts]);
    $actor = ($this->actor)('companies.view');

    expect($resolver->mayViewCounterpart($actor, $this->relationshipType, RelationDirection::Outgoing))->toBeFalse()
        ->and(fn () => $resolver->assertMayViewCounterpart($actor, $this->relationshipType, RelationDirection::Outgoing))
        ->toThrow(AuthorizationException::class);
});

it('lets an actor who may view the counterpart link a record', function (): void {
    $resolver = ($this->resolverKnowing)([(string) $this->contacts->getKey() => $this->contacts]);

    expect($resolver->mayViewCounterpart(
        ($this->actor)('contacts.view'),
        $this->relationshipType,
        RelationDirection::Outgoing,
    ))->toBeTrue();
});

it('refuses to link a counterpart object type that no longer exists', function (): void {
    $resolver = ($this->resolverKnowing)([]);

    expect($resolver->mayViewCounterpart(
        ($this->actor)('contacts.view'),
        $this->relationshipType,
        RelationDirection::Outgoing,
    ))->toBeFalse();
});

it('looks a link target up inside the tenant and the counterpart object type only', function (): void {
    $resolver = ($this->resolverKnowing)([]);
    $targetId = ModelStub::ulid('contact-record');

    $attempt = QueryShape::attemptedBy(fn (): CustomRecord => $resolver->targetFor(
        $this->company,
        $this->relationshipType,
        RelationDirection::Outgoing,
        $targetId,
    ));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->sql)->toContain('"tenant_id" = ?')
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->contacts->getKey()))->toBeTrue()
        ->and($attempt?->isKeyedTo('custom_records', $targetId))->toBeTrue();
});

it('refuses to move a record in the tree without the reparent permission', function (): void {
    $resolver = ($this->resolverKnowing)([]);
    $actor = ($this->actor)('companies.view');

    expect($resolver->mayReparent($actor, $this->company))->toBeFalse()
        ->and(fn () => $resolver->assertMayReparent($actor, $this->company))
        ->toThrow(AuthorizationException::class);
});

it('lets a holder of the reparent permission move a record in the tree', function (): void {
    expect(($this->resolverKnowing)([])->mayReparent(($this->actor)('companies.reparent'), $this->company))
        ->toBeTrue();
});

it('refuses to change a relation while no user is authenticated', function (): void {
    expect(fn () => ($this->relation)()->attach(ModelStub::ulid('contact-record')))
        ->toThrow(LogicException::class);
});

it('offers no bulk verb that would bypass the link guards', function (string $method): void {
    expect(fn () => ($this->relation)()->{$method}([]))->toThrow(LogicException::class);
})->with(['sync', 'syncWithoutDetaching', 'toggle']);

it('refuses a link payload that names no relationship type or target at all', function (): void {
    expect(function (): void {
        app(LinkRecordRelationAction::class)->execute(
            ($this->actor)('contacts.view'),
            $this->company,
            ['direction' => RelationDirection::Outgoing->value],
        );
    })->toThrow(ValidationException::class);
});

it('refuses a link payload whose target is no identifier', function (): void {
    expect(function (): void {
        app(LinkRecordRelationAction::class)->execute(
            ($this->actor)('contacts.view'),
            $this->company,
            [
                'relationship_type_id' => (string) $this->relationshipType->getKey(),
                'direction' => RelationDirection::Outgoing->value,
                'target_record_id' => 'nicht-ulid',
            ],
        );
    })->toThrow(ValidationException::class);
});
