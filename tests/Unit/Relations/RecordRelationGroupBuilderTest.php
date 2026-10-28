<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordCountData;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\RecordCountEstimator;
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
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
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
    $this->counts = Mockery::mock(RecordCountEstimator::class);

    $this->builder = new RecordRelationGroupBuilder(
        $this->relations,
        $this->treeQuery,
        $this->titleResolver,
        $this->searchPredicate,
        $this->endpoints,
        $this->counts,
    );

    $this->counterpartIds = [ModelStub::ulid('partner-one'), ModelStub::ulid('partner-two')];

    $this->linkedCounterparts = function (): void {
        StaticQueryConnection::install(fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "record_links"') => array_map(
                fn (string $counterpartId): array => [
                    'id' => ModelStub::ulid("link-{$counterpartId}"),
                    'tenant_id' => $this->tenant->getKey(),
                    'relationship_type_id' => $this->relationshipType->getKey(),
                    'from_record_id' => $this->record->getKey(),
                    'to_record_id' => $counterpartId,
                ],
                $this->counterpartIds,
            ),
            str_contains($sql, 'from "object_types"') => [[
                'id' => $this->counterpartType->getKey(),
                'tenant_id' => $this->tenant->getKey(),
                'name' => 'Partner',
            ]],
            default => [],
        });

        $this->relations->shouldReceive('counterpartObjectTypeId')->andReturn((string) $this->counterpartType->getKey());
        $this->endpoints->shouldReceive('newQuery')
            ->with((string) $this->counterpartType->getKey())
            ->andReturnUsing(fn () => CustomRecord::query()->ofType((string) $this->counterpartType->getKey()));
        $this->endpoints->shouldReceive('findMany')->andReturn(new Collection(array_map(
            fn (string $counterpartId): CustomRecord => ModelStub::make(CustomRecord::class, [
                'id' => $counterpartId,
                'tenant_id' => $this->tenant->getKey(),
                'object_type_id' => $this->counterpartType->getKey(),
                'record_number' => null,
            ]),
            $this->counterpartIds,
        ))->keyBy(fn (CustomRecord $counterpart): string => (string) $counterpart->getKey()));
        $this->titleResolver->shouldReceive('titleFor')->andReturn('Partner GmbH');
        $this->searchPredicate->shouldReceive('applyIdentity')->andReturnFalse();
    };

    $this->knownTypes = fn (RelationshipType ...$types): Collection => new Collection($types);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
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

it('reports the planner estimate of a large relation and leaves the last row open while a block comes back full', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    ($this->linkedCounterparts)();
    $this->counts->shouldReceive('count')->once()->andReturn(new RecordCountData(1234000, true));

    $page = $this->builder->entriesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 400, 2);

    expect($page['total'])->toBe(1234000)
        ->and($page['isEstimated'])->toBeTrue()
        ->and($page['lastRow'])->toBeNull()
        ->and($page['hasMore'])->toBeTrue()
        ->and($page['entries'])->toHaveCount(2);
});

it('closes an estimated relation at the block that comes back short instead of at the estimate', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    ($this->linkedCounterparts)();
    $this->counts->shouldReceive('count')->once()->andReturn(new RecordCountData(1234000, true));

    $page = $this->builder->entriesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 400, 25);

    expect($page['total'])->toBe(1234000)
        ->and($page['isEstimated'])->toBeTrue()
        ->and($page['lastRow'])->toBe(402)
        ->and($page['hasMore'])->toBeFalse();
});

it('keeps the exact total and its last row while the relation stays below the threshold', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    ($this->linkedCounterparts)();
    $this->counts->shouldReceive('count')->once()->andReturn(new RecordCountData(4, false));

    $page = $this->builder->entriesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 2, 2);

    expect($page['total'])->toBe(4)
        ->and($page['isEstimated'])->toBeFalse()
        ->and($page['lastRow'])->toBe(4)
        ->and($page['hasMore'])->toBeFalse();
});

it('estimates the total from the link query narrowed to the counterparts the scoped record query yields', function (): void {
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('assertMayViewCounterpart')->once();
    ($this->linkedCounterparts)();
    $this->counts->shouldReceive('count')->once()
        ->withArgs(function (Builder $query): bool {
            $shape = QueryShape::of($query);

            return $shape->targets('record_links')
                && str_contains($shape->sql, '"to_record_id" in (select "custom_records"."id" from "custom_records" where "custom_records"."object_type_id" = ? and "custom_records"."deleted_at" is null and "custom_records"."tenant_id" = ?)')
                && $shape->bindings === [
                    (string) $this->relationshipType->getKey(),
                    (string) $this->record->getKey(),
                    (string) $this->counterpartType->getKey(),
                    (string) $this->tenant->getKey(),
                    (string) $this->tenant->getKey(),
                ];
        })
        ->andReturn(new RecordCountData(2, false));

    $this->builder->entriesPage($this->record, $this->user, (string) $this->relationshipType->getKey(), RelationDirection::Outgoing, 0, 25);
});

it('marks the entry total of a relation group as estimated when the planner estimate is returned', function (): void {
    GateSpy::allowing();
    config()->set('engine.relations.max_entries', 2);
    $this->relations->shouldReceive('typesOf')->once()->andReturn(($this->knownTypes)($this->relationshipType));
    $this->relations->shouldReceive('mayViewCounterpart')->andReturnTrue();
    ($this->linkedCounterparts)();
    $this->counts->shouldReceive('count')->once()->andReturn(new RecordCountData(1234000, true));

    $groups = $this->builder->build($this->record, $this->user);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['entriesTotal'])->toBe(1234000)
        ->and($groups[0]['entriesTotalIsEstimated'])->toBeTrue()
        ->and($groups[0]['entriesTruncated'])->toBeTrue();
});
