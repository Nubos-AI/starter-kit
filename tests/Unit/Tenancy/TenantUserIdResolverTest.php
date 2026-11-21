<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantUserIdResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->resolver = new TenantUserIdResolver;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks nothing for an empty list', function (): void {
    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve(
        (string) $this->tenant->getKey(),
        [],
    ));

    expect($shape)->toBeNull()
        ->and($this->resolver->resolve((string) $this->tenant->getKey(), []))->toBe([]);
});

it('confines the lookup to the given tenant and to real people', function (): void {
    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve(
        (string) $this->tenant->getKey(),
        [ModelStub::ulid('member'), ModelStub::ulid('foreign')],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"is_service" = ?')
        ->and(array_slice($shape->bindings, 1, 1))->toBe([0])
        ->and($shape->isKeyedTo('users', ModelStub::ulid('member')))->toBeTrue()
        ->and($shape->hidesSoftDeleted('users'))->toBeTrue();
});
