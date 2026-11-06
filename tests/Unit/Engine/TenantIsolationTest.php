<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('puts the tenant of the bound context into every record query', function (): void {
    $shape = QueryShape::of(CustomRecord::class);

    expect($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('scopes the object type registry table to the bound tenant as well', function (): void {
    expect(QueryShape::of(ObjectType::class)->isScopedToTenant('object_types', (string) $this->tenant->getKey()))
        ->toBeTrue();
});

it('carries the tenant condition into a lookup by primary key', function (): void {
    $key = ModelStub::ulid('foreign-record');

    $attempt = QueryShape::attemptedBy(static fn (): ?CustomRecord => CustomRecord::query()->find($key));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->isKeyedTo('custom_records', $key))->toBeTrue()
        ->and($attempt?->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('blocks every row instead of leaking one when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(CustomRecord::class)->blocksEveryRow())->toBeTrue()
        ->and(QueryShape::of(ObjectType::class)->blocksEveryRow())->toBeTrue();
});

it('drops the tenant condition only where a caller asks for it explicitly', function (): void {
    $shape = QueryShape::of(CustomRecord::withoutTenantScope());

    expect($shape->hasColumnCondition('custom_records', 'tenant_id'))->toBeFalse()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse()
        ->and($shape->targets('custom_records'))->toBeTrue();
});
