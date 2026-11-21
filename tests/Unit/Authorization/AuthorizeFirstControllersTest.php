<?php

declare(strict_types=1);

use App\Actions\Authorization\BulkDeleteRolesAction;
use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Actions\Governance\BulkDeleteAbsenceDelegationsAction;
use App\Actions\Governance\CreateAbsenceDelegationAction;
use App\Actions\Governance\DeleteAbsenceDelegationAction;
use App\Actions\Governance\UpdateAbsenceDelegationAction;
use App\Actions\Teams\BulkDeleteTeamsAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Tenancy\UpdateTenantSettingsAction;
use App\Actions\Users\BulkDeleteUsersAction;
use App\Actions\Users\InviteUsersAction;
use App\Http\Controllers\Authorization\RolesController;
use App\Http\Controllers\Settings\AbsencesController;
use App\Http\Controllers\Teams\TeamsController;
use App\Http\Controllers\Tenancy\PreferencePolicyController;
use App\Http\Controllers\Users\UserInvitationsController;
use App\Http\Controllers\Users\UsersController;
use App\Models\AbsenceDelegation;
use App\Models\Role;
use App\Models\Team;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'unprivileged'));

    foreach ([
        CreateRoleAction::class, UpdateRoleAction::class, DeleteRoleAction::class, BulkDeleteRolesAction::class, SyncRolePermissionsAction::class,
        CreateTeamAction::class, BulkDeleteTeamsAction::class, BulkDeleteUsersAction::class, InviteUsersAction::class,
        UpdateTenantSettingsAction::class, CreateAbsenceDelegationAction::class, UpdateAbsenceDelegationAction::class,
        DeleteAbsenceDelegationAction::class, BulkDeleteAbsenceDelegationsAction::class,
    ] as $action) {
        $mock = Mockery::mock($action);
        $mock->shouldNotReceive('execute');
        app()->instance($action, $mock);
    }

    $this->request = function (): Request {
        $request = Request::create('/probe', 'POST', ['ids' => [ModelStub::ulid('anything')], 'name' => 'Probe']);
        $request->setUserResolver(fn (): User => $this->actor);

        return $request;
    };

    $this->role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('payroll'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'payroll', 'is_system' => false]);
    $this->absence = ModelStub::make(AbsenceDelegation::class, ['id' => ModelStub::ulid('absence'), 'tenant_id' => $this->tenant->getKey(), 'user_id' => ModelStub::ulid('someone-else')]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('authorizes every guarded controller method first and refuses before anything runs', function (string $controller, string $method, Closure $arguments, string $ability, Closure $subject): void {
    $gate = GateSpy::allowing();
    $request = ($this->request)();
    $thrown = null;

    $shape = QueryShape::attemptedBy(function () use ($controller, $method, $arguments, $request, &$thrown): void {
        try {
            app($controller)->{$method}($request, ...$arguments->call($this));
        } catch (AuthorizationException $exception) {
            $thrown = $exception;
        }
    });

    $expected = $subject->call($this);

    expect($thrown)->toBeInstanceOf(AuthorizationException::class)
        ->and($shape)->toBeNull()
        ->and($gate->calls[0]['ability'] ?? null)->toBe($ability)
        ->and(is_string($expected) ? $gate->calls[0]['arguments'][0] ?? null : ($gate->calls[0]['arguments'][0] ?? null)?->getKey())
        ->toBe(is_string($expected) ? $expected : $expected->getKey());
})->with([
    'roles index' => [RolesController::class, 'index', fn (): array => [], 'viewAny', fn (): string => Role::class],
    'roles create' => [RolesController::class, 'create', fn (): array => [], 'create', fn (): string => Role::class],
    'roles store' => [RolesController::class, 'store', fn (): array => [], 'create', fn (): string => Role::class],
    'roles edit' => [RolesController::class, 'edit', fn (): array => [$this->role], 'update', fn (): Role => $this->role],
    'roles update' => [RolesController::class, 'update', fn (): array => [$this->role], 'update', fn (): Role => $this->role],
    'roles destroy' => [RolesController::class, 'destroy', fn (): array => [$this->role], 'delete', fn (): Role => $this->role],
    'roles bulk destroy' => [RolesController::class, 'bulkDestroy', fn (): array => [], 'deleteAny', fn (): string => Role::class],
    'roles permissions' => [RolesController::class, 'updatePermissions', fn (): array => [$this->role], 'update', fn (): Role => $this->role],
    'teams index' => [TeamsController::class, 'index', fn (): array => [], 'viewAny', fn (): string => Team::class],
    'teams create' => [TeamsController::class, 'create', fn (): array => [], 'create', fn (): string => Team::class],
    'teams store' => [TeamsController::class, 'store', fn (): array => [], 'create', fn (): string => Team::class],
    'teams bulk destroy' => [TeamsController::class, 'bulkDestroy', fn (): array => [], 'deleteAny', fn (): string => Team::class],
    'users index' => [UsersController::class, 'index', fn (): array => [], 'viewAny', fn (): string => User::class],
    'users bulk destroy' => [UsersController::class, 'bulkDestroy', fn (): array => [], 'deleteAny', fn (): string => User::class],
    'invitation form' => [UserInvitationsController::class, 'create', fn (): array => [], 'invite', fn (): string => User::class],
    'invitation send' => [UserInvitationsController::class, 'store', fn (): array => [], 'invite', fn (): string => User::class],
    'personalization form' => [PreferencePolicyController::class, 'edit', fn (): array => [], 'view', fn (): string => TenantSetting::class],
    'personalization save' => [PreferencePolicyController::class, 'update', fn (): array => [app(UpdateTenantSettingsAction::class)], 'update', fn (): string => TenantSetting::class],
    'absences index' => [AbsencesController::class, 'index', fn (): array => [], 'viewAny', fn (): string => AbsenceDelegation::class],
    'absences store' => [AbsencesController::class, 'store', fn (): array => [], 'create', fn (): string => AbsenceDelegation::class],
    'absences update' => [AbsencesController::class, 'update', fn (): array => [$this->absence], 'update', fn (): AbsenceDelegation => $this->absence],
    'absences destroy' => [AbsencesController::class, 'destroy', fn (): array => [$this->absence], 'delete', fn (): AbsenceDelegation => $this->absence],
    'absences bulk destroy' => [AbsencesController::class, 'bulkDestroy', fn (): array => [], 'deleteAny', fn (): string => AbsenceDelegation::class],
]);
