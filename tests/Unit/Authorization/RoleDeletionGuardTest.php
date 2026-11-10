<?php

declare(strict_types=1);

use App\Actions\Authorization\BulkDeleteRolesAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Exceptions\Authorization\RoleInUseException;
use App\Http\Controllers\Authorization\RolesController;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\RoleDeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'role-admin');
    $this->role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('sales-role'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Vertrieb', 'scope' => 'tenant', 'is_system' => false, 'grants_subteam_visibility' => false]);

    $this->holding = function (int $users, int $teams): StaticQueryConnection {
        $userMorph = (new User)->getMorphClass();
        $teamMorph = (new Team)->getMorphClass();

        return StaticQueryConnection::install(
            fn (string $sql, array $bindings): array => match (true) {
                str_contains($sql, 'from "roles"') => [$this->role->getAttributes()],
                in_array($userMorph, $bindings, true) => [['role_id' => $this->role->getKey(), 'aggregate' => $users]],
                in_array($teamMorph, $bindings, true) => [['role_id' => $this->role->getKey(), 'aggregate' => $teams]],
                default => [['aggregate' => 0]],
            },
            static fn (): int => 1,
        );
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses to delete a role that a team still holds and names the teams', function (int $teams, string $message): void {
    ($this->holding)(0, $teams);

    expect(fn () => app(RoleDeletionGuard::class)->assertRoleIsUnused($this->role))
        ->toThrow(RoleInUseException::class, $message);
})->with([
    'one team' => [1, 'Diese Rolle ist noch einem Team zugewiesen und kann nicht gelöscht werden.'],
    'several teams' => [3, 'Diese Rolle ist noch 3 Teams zugewiesen und kann nicht gelöscht werden.'],
]);

it('still refuses to delete a role that a person holds', function (): void {
    ($this->holding)(1, 0);

    expect(fn () => app(RoleDeletionGuard::class)->assertRoleIsUnused($this->role))
        ->toThrow(RoleInUseException::class, 'Diese Rolle ist noch einer Person zugewiesen und kann nicht gelöscht werden.');
});

it('lets a role nobody holds be deleted', function (): void {
    ($this->holding)(0, 0);

    app(RoleDeletionGuard::class)->assertRoleIsUnused($this->role);

    expect(app(RoleDeletionGuard::class)->isUnused($this->role))->toBeTrue();
});

it('leaves a role a team still holds out of a bulk delete', function (): void {
    ($this->holding)(0, 2);
    Gate::before(static fn (): bool => true);
    $action = new BulkDeleteRolesAction(Mockery::mock(DeleteRoleAction::class), app(RoleDeletionGuard::class));

    expect((new ReflectionMethod($action, 'mayDelete'))->invoke($action, $this->actor, $this->role))->toBeFalse();
});

it('marks a role a team still holds as not deletable in the list and names the teams as the reason', function (): void {
    ($this->holding)(0, 2);
    GateSpy::allowing('viewAny', 'update', 'delete');
    AccessContext::actAs($this->actor);
    $request = Request::create('/engine/roles');
    $request->setUserResolver(fn () => $this->actor);

    $row = app(RolesController::class)->index($request)->toResponse($request)->original->getData()['page']['props']['roles'][0];

    expect($row['can_delete'])->toBeFalse()
        ->and($row['delete_reason'])->toBe('Diese Rolle ist noch 2 Teams zugewiesen und kann nicht gelöscht werden.');
});
