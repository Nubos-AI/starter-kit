<?php

declare(strict_types=1);

use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Http\Middleware\EnforceMaintenanceLock;
use App\Models\MaintenanceLock;
use App\Models\Tenant;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->sandboxId = ModelStub::ulid('maintenance-sandbox-tenant');
    $this->liveId = ModelStub::ulid('maintenance-live-tenant');

    $this->bindTenant = function (?string $sourceTenantId): Tenant {
        $tenant = ModelStub::make(Tenant::class, [
            'id' => $this->sandboxId,
            'source_tenant_id' => $sourceTenantId,
        ]);

        app()->instance('current_tenant', $tenant);
        Context::addHidden('tenant_id', $this->sandboxId);

        return $tenant;
    };

    $this->registryLocking = function (string ...$lockedTenantIds): MaintenanceLockRegistry {
        return new class(array_values($lockedTenantIds)) extends MaintenanceLockRegistry
        {
            /**
             * @var list<string>
             */
            public array $askedFor = [];

            /**
             * @param  list<string>  $locked
             */
            public function __construct(private readonly array $locked) {}

            public function assertWritable(string $tenantId, string $entryPoint, array $context = []): void
            {
                $this->askedFor[] = $tenantId;

                if (in_array($tenantId, $this->locked, true)) {
                    throw TenantUnderMaintenanceException::writeRefused($tenantId);
                }
            }
        };
    };

    $this->requestFor = function (string $method, ?string $routeName, array $middleware = []): Request {
        $request = Request::create('/engine/records', $method);

        $route = new Route([$method], 'engine/records', ['middleware' => $middleware]);

        if ($routeName !== null) {
            $route->name($routeName);
        }

        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a read through without ever asking the lock registry', function (): void {
    ($this->bindTenant)(null);
    $registry = ($this->registryLocking)($this->sandboxId);

    $response = (new EnforceMaintenanceLock($registry))->handle(($this->requestFor)('GET', 'engine.records.index'), $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and($registry->askedFor)->toBe([]);
});

it('lets a write through when no tenant is bound at all', function (): void {
    AccessContext::forgetTenant();
    $registry = ($this->registryLocking)($this->sandboxId);

    expect((new EnforceMaintenanceLock($registry))->handle(($this->requestFor)('POST', 'engine.records.store'), $this->reached)->getContent())
        ->toBe('reached')
        ->and($registry->askedFor)->toBe([]);
});

it('refuses a write while the bound tenant is locked', function (): void {
    ($this->bindTenant)(null);
    $registry = ($this->registryLocking)($this->sandboxId);

    expect(fn (): Response => (new EnforceMaintenanceLock($registry))->handle(($this->requestFor)('POST', 'engine.records.store'), $this->reached))
        ->toThrow(TenantUnderMaintenanceException::class);
});

it('keeps the route that lifts the lock reachable while the lock is held', function (): void {
    ($this->bindTenant)(null);
    $registry = ($this->registryLocking)($this->sandboxId);

    expect((new EnforceMaintenanceLock($registry))->handle(($this->requestFor)('DELETE', 'engine.maintenance.destroy'), $this->reached)->getContent())
        ->toBe('reached')
        ->and($registry->askedFor)->toBe([]);
});

it('keeps a posted read such as a grid or a preview reachable while the lock is held', function (): void {
    ($this->bindTenant)(null);
    $registry = ($this->registryLocking)($this->sandboxId);
    $middleware = new EnforceMaintenanceLock($registry);

    foreach (['engine.records.grid', 'reports.preview', 'notification-rules.preview', 'logout'] as $routeName) {
        expect($middleware->handle(($this->requestFor)('POST', $routeName), $this->reached)->getContent())->toBe('reached');
    }

    expect($registry->askedFor)->toBe([]);
});

it('checks the live tenant as well once a sandbox route writes back into it', function (): void {
    ($this->bindTenant)($this->liveId);
    $registry = ($this->registryLocking)();

    $response = (new EnforceMaintenanceLock($registry))->handle(
        ($this->requestFor)('POST', 'engine.promotion.store', ['live-tenant']),
        $this->reached,
    );

    expect($response->getContent())->toBe('reached')
        ->and($registry->askedFor)->toBe([$this->sandboxId, $this->liveId]);
});

it('refuses a sandbox write while the live tenant behind it is locked', function (): void {
    ($this->bindTenant)($this->liveId);
    $registry = ($this->registryLocking)($this->liveId);

    try {
        (new EnforceMaintenanceLock($registry))->handle(
            ($this->requestFor)('POST', 'engine.promotion.store', ['live-tenant']),
            $this->reached,
        );
    } catch (TenantUnderMaintenanceException $exception) {
        expect($exception->tenantId())->toBe($this->liveId)
            ->and($registry->askedFor)->toBe([$this->sandboxId, $this->liveId]);

        return;
    }

    $this->fail('the middleware wrote into a live tenant that is under maintenance');
});

it('leaves the live tenant alone on a route that never reaches it', function (): void {
    ($this->bindTenant)($this->liveId);
    $registry = ($this->registryLocking)($this->liveId);

    $response = (new EnforceMaintenanceLock($registry))->handle(
        ($this->requestFor)('POST', 'engine.records.store'),
        $this->reached,
    );

    expect($response->getContent())->toBe('reached')
        ->and($registry->askedFor)->toBe([$this->sandboxId]);
});

it('leaves the live tenant alone when the sandbox tenant knows no source tenant', function (): void {
    ($this->bindTenant)(null);
    $registry = ($this->registryLocking)();

    (new EnforceMaintenanceLock($registry))->handle(
        ($this->requestFor)('POST', 'engine.promotion.store', ['live-tenant']),
        $this->reached,
    );

    expect($registry->askedFor)->toBe([$this->sandboxId]);
});

it('reads an active lock across the tenant boundary so a foreign tenant cannot hide it', function (): void {
    ($this->bindTenant)(null);

    $attempt = QueryShape::attemptedBy(fn (): ?MaintenanceLock => (new MaintenanceLockRegistry)->activeFor($this->liveId));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('maintenance_locks'))->toBeTrue()
        ->and($attempt?->hasBinding($this->liveId))->toBeTrue()
        ->and($attempt?->hasBinding(MaintenanceLockStatus::Active->value))->toBeTrue()
        ->and($attempt?->isScopedToTenant('maintenance_locks', $this->sandboxId))->toBeFalse();
});

it('collects every locked tenant without narrowing the query to the bound tenant', function (): void {
    ($this->bindTenant)(null);

    $attempt = QueryShape::attemptedBy(fn (): array => (new MaintenanceLockRegistry)->lockedTenantIds());

    expect($attempt?->hasBinding(MaintenanceLockStatus::Active->value))->toBeTrue()
        ->and($attempt?->hasBinding($this->sandboxId))->toBeFalse();
});
