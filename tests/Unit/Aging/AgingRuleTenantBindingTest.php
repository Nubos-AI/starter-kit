<?php

declare(strict_types=1);

use App\Models\AgingRule;
use Tests\Support\AccessContext;
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
        ->and($shape->sql)->toContain('"aging_rules"."object_type_id" = "object_types"."id"')
        ->and($shape->isScopedToTenant('object_types', (string) $tenant->getKey()))->toBeTrue();
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
