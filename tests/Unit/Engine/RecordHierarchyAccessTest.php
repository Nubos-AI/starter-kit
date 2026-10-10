<?php

declare(strict_types=1);

use App\Exceptions\Engine\AmbiguousRecordTypeException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRegistry;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->objectTypeId = ModelStub::ulid('departments');
    $this->carrierId = ModelStub::ulid('departments-carrier');

    /** @var callable(?string):void */
    $this->hierarchyCarrier = function (?string $carrierId): void {
        $objectType = ModelStub::make(ObjectType::class, [
            'id' => $this->objectTypeId,
            'tenant_id' => $this->tenant->getKey(),
            'slug' => 'departments',
            'hierarchy_relationship_type_id' => $carrierId,
        ]);

        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('byId')->andReturn($objectType);
        $registry->shouldReceive('isRelationName')->andReturn(false);

        app()->instance(ObjectTypeRegistry::class, $registry);
    };

    /** @var callable():CustomRecord */
    $this->record = fn (): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('department-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(ObjectTypeRegistry::class);
});

it('refuses to walk the tree of a record whose object type is unknown', function (): void {
    ($this->hierarchyCarrier)($this->carrierId);

    $typeless = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('typeless')]);

    expect(fn (): mixed => $typeless->children()->toSql())
        ->toThrow(AmbiguousRecordTypeException::class)
        ->and(fn (): mixed => $typeless->parent()->toSql())
        ->toThrow(AmbiguousRecordTypeException::class);
});

it('reads the parent of a record only through the hierarchy carrier of its object type', function (): void {
    ($this->hierarchyCarrier)($this->carrierId);

    $record = ($this->record)();
    $shape = QueryShape::of($record->parent());

    expect($shape->sql)->toContain('"record_links"."relationship_type_id" in (?)')
        ->and($shape->hasBinding($this->carrierId))->toBeTrue()
        ->and($shape->hasBinding((string) $record->getKey()))->toBeTrue();
});

it('reads the children of a record in the order the links carry', function (): void {
    ($this->hierarchyCarrier)($this->carrierId);

    $shape = QueryShape::of(($this->record)()->children());

    expect($shape->hasBinding($this->carrierId))->toBeTrue()
        ->and($shape->sql)->toContain('order by "record_links"."position" asc, "record_links"."created_at" asc');
});

it('matches no link at all for an object type without a hierarchy carrier', function (): void {
    ($this->hierarchyCarrier)(null);

    $record = ($this->record)();

    expect(QueryShape::of($record->children())->sql)->toContain('and 0 = 1')
        ->and($record->ancestors()->nodes)->toBe([])
        ->and($record->descendants()->nodes)->toBe([]);
});

it('keeps the record query of a tree inside the tenant', function (): void {
    ($this->hierarchyCarrier)($this->carrierId);

    expect(QueryShape::of(($this->record)()->children())->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))
        ->toBeTrue();
});
