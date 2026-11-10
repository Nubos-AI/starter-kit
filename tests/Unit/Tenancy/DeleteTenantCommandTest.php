<?php

declare(strict_types=1);

use App\Actions\Tenancy\PurgeTenantAction;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\DTOs\Tenancy\PurgeReport;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('doomed-tenant');
    $this->actorId = ModelStub::ulid('doomed-tenant-admin');
    $this->roleId = ModelStub::ulid('doomed-tenant-super-admin');
    $this->authority = RoleAuthority::SuperAdmin->value;

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, '1 = 0') => [],
        str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Doomed', 'slug' => 'doomed']],
        str_contains($sql, 'from "users"') => [['id' => $this->actorId, 'tenant_id' => $this->tenantId, 'status' => UserStatus::Accepted->value, 'email' => 'admin@doomed.de']],
        str_contains($sql, 'from "role_assignments"') => [['role_id' => $this->roleId]],
        str_contains($sql, 'from "roles"') => [['id' => $this->roleId, 'tenant_id' => $this->tenantId, 'name' => 'Administrator', 'authority' => $this->authority]],
        default => [],
    });

    $this->purge = Mockery::mock(PurgeTenantAction::class);
    $this->guard = Mockery::mock(TenantDeletionGuardInterface::class);
    $this->locks = Mockery::mock(MaintenanceLockRegistry::class);

    app()->instance(PurgeTenantAction::class, $this->purge);
    app()->instance(TenantDeletionGuardInterface::class, $this->guard);
    app()->instance(MaintenanceLockRegistry::class, $this->locks);

    $this->delete = fn () => $this->artisan('tenants:delete', [
        '--tenant' => $this->tenantId,
        '--as-user' => $this->actorId,
        '--confirm-name' => 'Doomed',
    ]);
});

afterEach(function (): void {
    foreach ([PurgeTenantAction::class, TenantDeletionGuardInterface::class, MaintenanceLockRegistry::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('reads the roles of the acting super admin inside the tenant being deleted and purges it', function (): void {
    $this->guard->shouldReceive('assertDeletable')->once();
    $this->locks->shouldReceive('assertWritable')->once();
    $this->purge->shouldReceive('execute')->once()->with(Mockery::type(Tenant::class))->andReturn(new PurgeReport([], 0, 0, []));

    ($this->delete)()->assertSuccessful();

    $roleLookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "roles"'));
    $shape = new QueryShape($roleLookup['sql'], $roleLookup['bindings']);

    $assignmentLookup = collect($this->connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "role_assignments"'));

    expect($shape->isScopedToTenant('roles', $this->tenantId))->toBeTrue()
        ->and($assignmentLookup['bindings'])->toContain((new Tenant)->getMorphClass(), $this->tenantId)
        ->and($assignmentLookup['bindings'])->not->toContain((new Team)->getMorphClass())
        ->and(app()->bound('current_tenant'))->toBeFalse();
});

it('refuses an acting user without escalated authority and never purges', function (): void {
    $this->authority = null;

    $this->guard->shouldNotReceive('assertDeletable');
    $this->purge->shouldNotReceive('execute');

    ($this->delete)()->assertFailed();
});
