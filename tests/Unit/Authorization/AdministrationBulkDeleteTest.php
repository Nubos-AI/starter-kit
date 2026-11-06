<?php

declare(strict_types=1);

use App\Actions\Api\BulkRevokePersonalApiTokensAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Actions\Authorization\BulkDeleteRolesAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Users\BulkDeleteUsersAction;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Http\Controllers\Users\UsersController;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleDeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'bulk-delete-actor');

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('bulk-delete-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Vertrieb',
        'is_system' => false,
    ]);

    $this->roleGuard = Mockery::mock(RoleDeletionGuard::class);

    $this->bulkDeleteRoles = new BulkDeleteRolesAction(
        Mockery::mock(DeleteRoleAction::class),
        $this->roleGuard,
    );

    $this->mayDeleteRole = fn (Role $role): bool => (new ReflectionMethod($this->bulkDeleteRoles, 'mayDelete'))
        ->invoke($this->bulkDeleteRoles, $this->actor, $role);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('bulk deletes only the living users of the acting tenant', function (): void {
    $wanted = ModelStub::ulid('bulk-delete-user');

    $shape = QueryShape::attemptedBy(fn (): mixed => app(BulkDeleteUsersAction::class)
        ->execute($this->actor, ['ids' => [$wanted]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->isScopedToTenant('users', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('users'))->toBeTrue()
        ->and($shape->hasBinding($wanted))->toBeTrue();
});

it('leaves a user out of the batch whom the user policy refuses to delete', function (): void {
    $target = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('bulk-delete-target'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    Gate::before(static fn (): bool => false);

    $action = app(BulkDeleteUsersAction::class);

    expect((new ReflectionMethod($action, 'mayDelete'))->invoke($action, $this->actor, $target))->toBeFalse();
});

it('answers a lockout during a bulk user delete as a message on the user field', function (): void {
    $bulkDeleteUsers = Mockery::mock(BulkDeleteUsersAction::class);
    $bulkDeleteUsers->shouldReceive('execute')->once()->andThrow(SelfLockoutException::lastEscalatedHolderCannotBeDeleted());
    app()->instance(BulkDeleteUsersAction::class, $bulkDeleteUsers);

    $request = Request::create('/engine/users/bulk-delete', 'POST', ['ids' => [ModelStub::ulid('bulk-delete-target')]]);
    $request->setUserResolver(fn (): User => $this->actor);

    try {
        app(UsersController::class)->bulkDestroy($request);
        $this->fail('The lockout reached the user without a field message.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('user');
    }
});

it('bulk deletes only the roles of the acting tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->bulkDeleteRoles
        ->execute($this->actor, ['ids' => [(string) $this->role->getKey()]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('roles'))->toBeTrue()
        ->and($shape->isScopedToTenant('roles', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->role->getKey()))->toBeTrue();
});

it('leaves a role out of the batch that users still hold', function (): void {
    Gate::before(static fn (): bool => true);
    $this->roleGuard->shouldReceive('assignedUserCount')->with($this->role)->andReturn(2);

    expect(($this->mayDeleteRole)($this->role))->toBeFalse();
});

it('leaves a role out of the batch that the role policy refuses to delete', function (): void {
    Gate::before(static fn (): bool => false);
    $this->roleGuard->shouldNotReceive('assignedUserCount');

    expect(($this->mayDeleteRole)($this->role))->toBeFalse();
});

it('takes an unassigned role the policy allows into the batch', function (): void {
    Gate::before(static fn (): bool => true);
    $this->roleGuard->shouldReceive('assignedUserCount')->with($this->role)->andReturn(0);

    expect(($this->mayDeleteRole)($this->role))->toBeTrue();
});

it('bulk revokes only the personal tokens of the acting user', function (): void {
    $action = new BulkRevokePersonalApiTokensAction(new RevokeApiTokenAction);

    $shape = QueryShape::attemptedBy(fn (): mixed => $action->execute($this->actor, ['ids' => [17, 18]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('personal_access_tokens'))->toBeTrue()
        ->and($shape->sql)->toContain('"tokenable_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->actor->getKey())
        ->and($shape->sql)->toContain('in (17, 18)');
});

it('refuses a personal token identifier that is not a number', function (): void {
    expect(fn (): mixed => (new BulkRevokePersonalApiTokensAction(new RevokeApiTokenAction))
        ->execute($this->actor, ['ids' => ['alle']]))
        ->toThrow(ValidationException::class);
});

it('guards the bulk delete routes like their single delete counterparts', function (): void {
    $userRoute = RouteShape::named('engine.users.bulkDestroy');
    $permissions = array_filter(
        $userRoute->declaredMiddleware(),
        static fn (string $entry): bool => str_starts_with($entry, 'permission:'),
    );

    expect($userRoute->handledBy())->toContain('UsersController@bulkDestroy')
        ->and($permissions)->toBeEmpty()
        ->and(RouteShape::named('engine.roles.bulkDestroy')->hasDeclaredMiddleware('permission:roles.delete'))->toBeTrue()
        ->and(RouteShape::named('engine.roles.bulkDestroy')->handledBy())->toContain('RolesController@bulkDestroy')
        ->and(RouteShape::named('settings.api-tokens.bulkDestroy')->handledBy())->toContain('ApiTokensController@bulkDestroy');
});
