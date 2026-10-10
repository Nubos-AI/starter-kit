<?php

declare(strict_types=1);

use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordRelationGroupBuilder;
use App\Support\Engine\RecordRelationResolver;
use App\Support\Engine\RecordSearchPredicate;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Engine\RecordTreeQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);
    AccessContext::suspendRowAccess();

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

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->relationshipType = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('companies-partners'),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->counterpartType->getKey(),
        'is_hierarchy' => false,
    ]);

    $this->relations = Mockery::mock(RecordRelationResolver::class);
    $this->treeQuery = Mockery::mock(RecordTreeQuery::class);
    $this->titleResolver = Mockery::mock(RecordTitleResolver::class);
    $this->searchPredicate = Mockery::mock(RecordSearchPredicate::class);
    $this->endpoints = Mockery::mock(RecordEndpointResolver::class);

    $this->builder = new RecordRelationGroupBuilder(
        $this->relations,
        $this->treeQuery,
        $this->titleResolver,
        $this->searchPredicate,
        $this->endpoints,
    );

    $this->knownTypes = fn (RelationshipType ...$types): Collection => new Collection($types);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a block for a relationship type that belongs to another object type', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->with($this->objectType)->andReturn(($this->knownTypes)());
    $this->relations->shouldNotReceive('assertMayViewCounterpart');

    try {
        $this->builder->entriesPage($this->record, $this->user, ModelStub::ulid('foreign'), RelationDirection::Outgoing, 0, 25);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['relationshipTypeId']);
    }
});

it('refuses the entries of a counterpart the user may not view before it reads a single link', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once()
        ->with($this->user, $this->relationshipType, RelationDirection::Outgoing)
        ->andThrow(new AuthorizationException);
    $this->endpoints->shouldNotReceive('newQuery');

    expect(fn () => $this->builder->entriesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 0, 25))
        ->toThrow(AuthorizationException::class);
});

it('refuses the candidates of a counterpart the user may not view', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once()->andThrow(new AuthorizationException);
    $this->endpoints->shouldNotReceive('newQuery');

    expect(fn () => $this->builder->candidatesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 0, 25))
        ->toThrow(AuthorizationException::class);
});

it('lists the links of the record through the counterparts the scoped record query yields', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    $this->relations->shouldReceive('counterpartObjectTypeId')->andReturn((string) $this->counterpartType->getKey());
    $this->endpoints->shouldReceive('newQuery')->once()
        ->with((string) $this->counterpartType->getKey())
        ->andReturn(CustomRecord::query()->ofType((string) $this->counterpartType->getKey()));
    $this->searchPredicate->shouldReceive('applyIdentity')->once()->andReturnFalse();

    $shape = QueryShape::attemptedBy(fn () => $this->builder->entriesPage(
        $this->record,
        $this->user,
        (string) $this->relationshipType->getKey(),
        RelationDirection::Outgoing,
        0,
        25,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('record_links'))->toBeTrue()
        ->and($shape->sql)->toContain('"relationship_type_id" = ?')
        ->and($shape->sql)->toContain('"from_record_id" = ?')
        ->and($shape->sql)->toContain('"to_record_id" in (select "custom_records"."id" from "custom_records"')
        ->and($shape->sql)->toContain('"custom_records"."object_type_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->counterpartType->getKey());
});

it('narrows the listed links by the identity search once the predicate applies one', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    $this->relations->shouldReceive('counterpartObjectTypeId')->andReturn((string) $this->counterpartType->getKey());
    $this->endpoints->shouldReceive('newQuery')->once()
        ->andReturn(CustomRecord::query()->ofType((string) $this->counterpartType->getKey()));
    $this->searchPredicate->shouldReceive('applyIdentity')->once()
        ->andReturnUsing(function (Builder $query, $user, string $objectTypeId, ?string $term): bool {
            $query->where('record_number', $term);

            return true;
        });

    $shape = QueryShape::attemptedBy(fn () => $this->builder->entriesPage(
        $this->record,
        $this->user,
        (string) $this->relationshipType->getKey(),
        RelationDirection::Outgoing,
        0,
        25,
        'R-42',
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('select "id" from "custom_records"')
        ->and($shape->sql)->toContain('"record_number" = ?')
        ->and($shape->bindings)->toContain('R-42');
});
