<?php

declare(strict_types=1);

use App\Support\Engine\OutboxRelay;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->relay = app(OutboxRelay::class);
    $this->lockedTenantId = ModelStub::ulid('locked-tenant');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('claims only unpublished events, oldest sequence first, and never the same row twice', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([], 25));

    expect($shape->targets('outbox_events'))->toBeTrue()
        ->and($shape->sql)->toContain('"published_at" is null')
        ->and($shape->sql)->toContain('order by "sequence" asc')
        ->and($shape->sql)->toContain('limit 25')
        ->and($shape->sql)->toContain('for update skip locked');
});

it('leaves the events of a tenant under maintenance in the outbox', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([$this->lockedTenantId], 25));

    expect($shape->sql)->toContain('"tenant_id" not in (?)')
        ->and($shape->hasBinding($this->lockedTenantId))->toBeTrue();
});

it('adds no tenant condition at all while no tenant is under maintenance', function (): void {
    expect(QueryShape::of($this->relay->claimQuery([], 25))->sql)->not->toContain('tenant_id');
});

it('reads the outbox across every tenant, unbound by the tenant scope', function (): void {
    AccessContext::tenant();

    expect(QueryShape::of($this->relay->claimQuery([], 25))->sql)->not->toContain('"outbox_events"."tenant_id" =');
});
