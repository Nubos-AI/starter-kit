<?php

declare(strict_types=1);

use App\Actions\Engine\PurgeRecordAction;
use App\Activities\Trash\PurgeTrashedRecordsActivity;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\OutboxRelay;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Trash\PurgeDeadline;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

    $this->lockedTenantId = ModelStub::ulid('locked-tenant');

    /** @var callable(list<string>):MaintenanceLockRegistry */
    $this->registryLocking = function (array $lockedTenantIds): MaintenanceLockRegistry {
        $registry = Mockery::mock(MaintenanceLockRegistry::class);
        $registry->shouldReceive('lockedTenantIds')->andReturn($lockedTenantIds);

        return $registry;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('leaves the events of every locked tenant out of the claim', function (): void {
    $relay = new OutboxRelay(($this->registryLocking)([$this->lockedTenantId]));

    $shape = QueryShape::of($relay->claimQuery([$this->lockedTenantId], 2));

    expect($shape->targets('outbox_events'))->toBeTrue()
        ->and($shape->sql)->toContain('"published_at" is null')
        ->and($shape->sql)->toContain('"tenant_id" not in')
        ->and($shape->hasBinding($this->lockedTenantId))->toBeTrue()
        ->and($shape->sql)->toContain('order by "sequence" asc')
        ->and($shape->sql)->toContain('limit 2')
        ->and($shape->sql)->toContain('for update skip locked');
});

it('carries the locked tenants the registry names into the selection it runs', function (): void {
    $registry = ($this->registryLocking)([$this->lockedTenantId]);

    $activity = new PurgeTrashedRecordsActivity(
        Mockery::mock(PurgeRecordAction::class),
        app(PurgeDeadline::class),
        $registry,
    );

    $shape = QueryShape::attemptedBy(fn (): mixed => $activity->expiredRecordIds());

    expect($shape)->not->toBeNull()
        ->and($shape?->hasBinding($this->lockedTenantId))->toBeTrue();

    $registry->shouldHaveReceived('lockedTenantIds');
});
