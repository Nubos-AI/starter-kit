<?php

declare(strict_types=1);

use App\Actions\Tenancy\DeleteTenantAction;
use App\Actions\Tenancy\PurgeTenantAction;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\DTOs\Tenancy\PurgeReport;
use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = ModelStub::make(Tenant::class, [
        'id' => ModelStub::ulid('deleted-tenant'),
        'name' => 'Deleted',
        'slug' => 'deleted',
    ]);

    $this->purge = Mockery::mock(PurgeTenantAction::class);
    $this->guard = Mockery::mock(TenantDeletionGuardInterface::class);
    $this->locks = Mockery::mock(MaintenanceLockRegistry::class);

    app()->instance(PurgeTenantAction::class, $this->purge);
    app()->instance(TenantDeletionGuardInterface::class, $this->guard);
    app()->instance(MaintenanceLockRegistry::class, $this->locks);

    $this->role = static fn (string $seed, RoleAuthority $authority): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'authority' => $authority->value,
    ]);

    $this->permissions = Mockery::mock(PermissionResolver::class);
    $this->permissions->shouldReceive('allows')->byDefault()->andReturnFalse();
    app()->instance(PermissionResolver::class, $this->permissions);

    /** @var callable(list<Role>, list<Role>):User */
    $this->actor = function (array $tenantWideRoles, array $teamScopedRoles = []): User {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('assignedRolesFor')->andReturnUsing(
            fn (?Model $scope = null): Collection => new Collection($scope instanceof Tenant && $scope->is($this->tenant)
                ? $tenantWideRoles
                : [...$tenantWideRoles, ...$teamScopedRoles]),
        );
        $actor->shouldReceive('getKey')->andReturn(ModelStub::ulid('deleting-actor'));

        return $actor;
    };

    $this->expectPurge = function (): PurgeReport {
        $report = new PurgeReport([], 0, 0, []);

        $this->guard->shouldReceive('assertDeletable')->once()->with($this->tenant);
        $this->locks->shouldReceive('assertWritable')->once()->with(ModelStub::ulid('deleted-tenant'), 'tenant_deletion', Mockery::type('array'));
        $this->purge->shouldReceive('execute')->once()->with($this->tenant)->andReturn($report);

        return $report;
    };

    $this->expectRefusal = function (User $actor): void {
        $this->guard->shouldNotReceive('assertDeletable');
        $this->purge->shouldNotReceive('execute');

        expect(fn (): PurgeReport => app(DeleteTenantAction::class)->execute($this->tenant, $actor))
            ->toThrow(AuthorizationException::class);
    };
});

afterEach(function (): void {
    foreach ([PurgeTenantAction::class, TenantDeletionGuardInterface::class, MaintenanceLockRegistry::class, PermissionResolver::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Mockery::close();
});

it('lets the tenant wide owner with an authority role delete the tenant', function (RoleAuthority $authority): void {
    $report = ($this->expectPurge)();

    expect(app(DeleteTenantAction::class)->execute($this->tenant, ($this->actor)([($this->role)('tenant-wide-owner', $authority)])))->toBe($report);
})->with([
    'super admin' => [RoleAuthority::SuperAdmin],
    'scope admin' => [RoleAuthority::ScopeAdmin],
]);

it('lets a user holding the tenant wide tenants.delete permission delete the tenant', function (): void {
    $this->permissions->shouldReceive('allows')
        ->with(Mockery::type(User::class), 'tenants.delete', Mockery::on(fn (mixed $scope): bool => $scope === $this->tenant))
        ->andReturnTrue();
    $report = ($this->expectPurge)();

    expect(app(DeleteTenantAction::class)->execute($this->tenant, ($this->actor)([])))->toBe($report);
});

it('refuses a scope admin whose authority is limited to a team', function (): void {
    ($this->expectRefusal)(($this->actor)([], [($this->role)('team-scope-admin', RoleAuthority::ScopeAdmin)]));
});

it('refuses a user without authority and without the tenants.delete permission and never purges', function (): void {
    ($this->expectRefusal)(($this->actor)([]));
});

it('never accepts the tenants.delete permission when it is only granted outside the tenant wide scope', function (): void {
    $this->permissions->shouldReceive('allows')
        ->with(Mockery::type(User::class), 'tenants.delete', Mockery::on(fn (mixed $scope): bool => $scope !== $this->tenant))
        ->andReturnTrue();

    ($this->expectRefusal)(($this->actor)([]));
});
