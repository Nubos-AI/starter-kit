<?php

declare(strict_types=1);

use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordRelationResolver;
use Illuminate\Auth\Access\AuthorizationException;
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

    $this->counterpartType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('partners'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'partners',
    ]);

    $this->relationshipType = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('companies-partners'),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->counterpartType->getKey(),
        'is_hierarchy' => false,
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->user = AccessContext::user($this->tenant);

    $this->registry = Mockery::mock(ObjectTypeRegistry::class);
    $this->resolver = new RecordRelationResolver($this->registry);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('names the target end as the counterpart of an outgoing relation and the source end of an incoming one', function (): void {
    expect($this->resolver->counterpartObjectTypeId($this->relationshipType, RelationDirection::Outgoing))
        ->toBe((string) $this->counterpartType->getKey())
        ->and($this->resolver->counterpartObjectTypeId($this->relationshipType, RelationDirection::Incoming))
        ->toBe((string) $this->objectType->getKey());
});

it('asks for the view permission of the counterpart object type, not of the own one', function (): void {
    $permissions = AccessContext::grant('partners.view');
    $this->registry->shouldReceive('find')->once()
        ->with((string) $this->counterpartType->getKey())
        ->andReturn($this->counterpartType);

    expect($this->resolver->mayViewCounterpart($this->user, $this->relationshipType, RelationDirection::Outgoing))->toBeTrue()
        ->and($permissions->askedFor)->toBe(['partners.view']);
});

it('refuses a counterpart the user may not view', function (): void {
    AccessContext::grant('companies.view');
    $this->registry->shouldReceive('find')->andReturn($this->counterpartType);

    expect(fn () => $this->resolver->assertMayViewCounterpart($this->user, $this->relationshipType, RelationDirection::Outgoing))
        ->toThrow(AuthorizationException::class);
});

it('refuses a counterpart object type that no longer resolves without asking for any permission', function (): void {
    $permissions = AccessContext::grant('partners.view');
    $this->registry->shouldReceive('find')->once()->andReturnNull();

    expect($this->resolver->mayViewCounterpart($this->user, $this->relationshipType, RelationDirection::Incoming))->toBeFalse()
        ->and($permissions->askedFor)->toBe([]);
});

it('asks for the reparent ability of the object type the record belongs to', function (): void {
    $permissions = AccessContext::grant('companies.reparent');

    expect($this->resolver->mayReparent($this->user, $this->record))->toBeTrue()
        ->and($permissions->askedFor)->toBe(['companies.reparent']);
});

it('refuses to reparent for a user who only holds the update permission', function (): void {
    AccessContext::grant('companies.update', 'companies.view');

    expect(fn () => $this->resolver->assertMayReparent($this->user, $this->record))
        ->toThrow(AuthorizationException::class);
});

it('looks the target up inside the tenant of the record and inside the counterpart object type only', function (): void {
    $targetId = ModelStub::ulid('partner-record');

    $shape = QueryShape::attemptedBy(fn () => $this->resolver->targetFor(
        $this->record,
        $this->relationshipType,
        RelationDirection::Outgoing,
        $targetId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->counterpartType->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('custom_records', $targetId))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('looks a relationship type up by its key under the tenant scope', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->typeFor(
        $this->objectType,
        (string) $this->relationshipType->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('relationship_types'))->toBeTrue()
        ->and($shape->isScopedToTenant('relationship_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('relationship_types', (string) $this->relationshipType->getKey()))->toBeTrue();
});
