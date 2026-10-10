<?php

declare(strict_types=1);

use App\Models\AgingRule;
use App\Models\ObjectType;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('binds an aging rule to the tenant through its object type instead of a column of its own', function (): void {
    $tenant = AccessContext::tenant();

    $shape = QueryShape::of(AgingRule::class);

    expect($shape->targets('aging_rules'))->toBeTrue()
        ->and($shape->hasColumnCondition('aging_rules', 'tenant_id'))->toBeFalse()
        ->and($shape->sql)->toContain('exists (select * from "object_types"')
        ->and($shape->isScopedToTenant('object_types', (string) $tenant->getKey()))->toBeTrue();
});

it('ties the object type subquery to the rule row it belongs to', function (): void {
    AccessContext::tenant();

    expect(QueryShape::of(AgingRule::class)->sql)
        ->toContain('"aging_rules"."object_type_id" = "object_types"."id"');
});

it('blocks every aging rule when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(AgingRule::class)->blocksEveryRow())->toBeTrue();
});

it('keeps soft deleted aging rules and soft deleted object types out of the default query', function (): void {
    AccessContext::tenant();

    $shape = QueryShape::of(AgingRule::class);

    expect($shape->hidesSoftDeleted('aging_rules'))->toBeTrue()
        ->and($shape->hidesSoftDeleted('object_types'))->toBeTrue();
});

it('still reaches the tenant of the object type when the rules are read for one object type', function (): void {
    $tenant = AccessContext::tenant();
    $objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('aging-object-type'),
        'tenant_id' => $tenant->getKey(),
    ]);

    $shape = QueryShape::of(AgingRule::query()->where('object_type_id', $objectType->getKey()));

    expect($shape->hasBinding((string) $objectType->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('object_types', (string) $tenant->getKey()))->toBeTrue();
});
