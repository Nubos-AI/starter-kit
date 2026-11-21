<?php

declare(strict_types=1);

use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\SyncFieldPermissionsAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'role-editor'));
    $this->permissionId = (string) ModelStub::ulid('members.password');
    FakePresenceVerifier::holding(['permissions' => [$this->permissionId]]);

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('payroll'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'payroll',
        'scope' => RoleScope::Tenant->value,
        'is_system' => false,
        'grants_subteam_visibility' => false,
    ]);

    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->syncPermissions = Mockery::mock(SyncRolePermissionsAction::class);
    $this->syncFields = Mockery::mock(SyncFieldPermissionsAction::class);
    $this->lockout = Mockery::mock(SelfLockoutGuard::class);
    $this->lockout->shouldReceive('assertActingUserRetainsRoleManagement');
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    app()->instance(SyncRolePermissionsAction::class, $this->syncPermissions);
    app()->instance(SyncFieldPermissionsAction::class, $this->syncFields);
    app()->instance(SelfLockoutGuard::class, $this->lockout);
    app()->instance(PermissionSubsetGuard::class, $this->subsetGuard);

    $this->input = ['name' => 'Lohnbuchhaltung', 'scope' => RoleScope::Tenant->value, 'permission_ids' => [$this->permissionId]];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to edit a role beyond the rights of the editor before anything is written', function (): void {
    $this->subsetGuard->shouldReceive('assertMayEditRole')->once()->with($this->role)->andThrow(new AuthorizationException('beyond'));
    $this->syncPermissions->shouldNotReceive('execute');

    expect(fn () => app(UpdateRoleAction::class)->execute($this->actor, $this->role, $this->input))
        ->toThrow(AuthorizationException::class)
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('rolls the renamed role back when its permissions cannot be synced', function (): void {
    $this->subsetGuard->shouldReceive('assertMayEditRole')->once();
    $this->syncPermissions->shouldReceive('execute')->once()->andThrow(new AuthorizationException('beyond'));

    expect(fn () => app(UpdateRoleAction::class)->execute($this->actor, $this->role, $this->input))
        ->toThrow(AuthorizationException::class)
        ->and($this->connection->writtenStatements)->not->toBe([])
        ->and($this->connection->rolledBackStatements)->toBe($this->connection->writtenStatements)
        ->and($this->connection->openedTransactions)->toBe(1);
});

it('rolls a created role back when its permissions cannot be synced', function (): void {
    $this->syncPermissions->shouldReceive('execute')->once()->andThrow(new AuthorizationException('beyond'));

    expect(fn () => app(CreateRoleAction::class)->execute($this->actor, $this->input))
        ->toThrow(AuthorizationException::class)
        ->and($this->connection->writtenStatements)->not->toBe([])
        ->and($this->connection->rolledBackStatements)->toBe($this->connection->writtenStatements);
});
