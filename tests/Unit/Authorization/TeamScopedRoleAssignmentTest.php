<?php

declare(strict_types=1);

use App\Actions\Authorization\AssignRoleAction;
use App\Actions\Authorization\SyncUserRolesAction;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AssignmentScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->target = AccessContext::user($this->tenant, [], 'managed-member');
    $this->teamMorph = (new Team)->getMorphClass();
    $this->tenantMorph = $this->tenant->getMorphClass();

    $this->leadRole = ['id' => ModelStub::ulid('team-lead'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Teamleitung', 'scope' => RoleScope::Team->value, 'is_system' => false];
    $this->editorRole = ['id' => ModelStub::ulid('editor'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Redaktion', 'scope' => RoleScope::Tenant->value, 'is_system' => false];
    $this->sales = ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Vertrieb', 'slug' => 'vertrieb', 'descendant_team_ids' => '[]'];
    $this->assignments = [];

    StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "role_assignments"') => $this->assignments,
            str_contains($sql, 'from "roles"') => array_values(array_filter(
                [$this->leadRole, $this->editorRole],
                static fn (array $role): bool => in_array($role['id'], $bindings, true),
            )),
            str_contains($sql, 'from "teams"') => in_array($this->sales['id'], $bindings, true) ? [$this->sales] : [],
            default => [],
        },
        static fn (): int => 1,
    );

    FakePresenceVerifier::holding(['roles' => [$this->leadRole['id'], $this->editorRole['id']], 'teams' => [$this->sales['id']]]);

    $this->assignRole = Mockery::mock(AssignRoleAction::class);
    app()->instance(AssignRoleAction::class, $this->assignRole);

    $this->teamRole = fn (string $teamId): array => ['role_id' => $this->leadRole['id'], 'team_id' => $teamId];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Auth::forgetUser();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('resolves a team-scoped role to the chosen team of the bound tenant', function (): void {
    $role = ModelStub::make(Role::class, $this->leadRole);

    $scope = app(AssignmentScopeResolver::class)->resolve($role, $this->sales['id']);

    expect($scope)->toBeInstanceOf(Team::class)
        ->and($scope?->getKey())->toBe($this->sales['id']);
});

it('refuses a team-scoped role for a team the bound tenant does not own', function (): void {
    $role = ModelStub::make(Role::class, $this->leadRole);

    expect(fn () => app(AssignmentScopeResolver::class)->resolve($role, ModelStub::ulid('foreign-team')))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['team_roles' => ['Das gewählte Team ist nicht verfügbar.']]);
        });
});

it('refuses to hand out a team-scoped role with elevated authority for a single team', function (): void {
    $role = ModelStub::make(Role::class, [...$this->leadRole, 'authority' => RoleAuthority::ScopeAdmin->value]);

    expect(fn () => app(AssignmentScopeResolver::class)->resolve($role, $this->sales['id']))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['team_roles' => ['Die Rolle [Teamleitung] trägt eine erweiterte Berechtigung und kann nicht für ein einzelnes Team vergeben werden.']]);
        });
});

it('assigns a team-scoped role to a user for the chosen team', function (): void {
    $this->assignRole->shouldReceive('assign')->once()
        ->withArgs(fn (User $user, Role $role, ?Team $scope): bool => $user->is($this->target)
            && $role->getKey() === $this->leadRole['id']
            && $scope?->getKey() === $this->sales['id']);
    $this->assignRole->shouldNotReceive('removeAssignment');

    app(SyncUserRolesAction::class)->execute($this->target, [], [($this->teamRole)($this->sales['id'])]);
});

it('removes a team-scoped role of a user again by its stored team', function (): void {
    $this->assignments = [['id' => ModelStub::ulid('lead-in-sales'), 'role_id' => $this->leadRole['id'], 'model_type' => 'user', 'model_id' => $this->target->getKey(), 'scope_type' => $this->teamMorph, 'scope_id' => $this->sales['id']]];
    $this->assignRole->shouldNotReceive('assign');
    $this->assignRole->shouldReceive('removeAssignment')->once()
        ->withArgs(fn (User $user, RoleAssignment $assignment): bool => $assignment->scope_type === $this->teamMorph && $assignment->scope_id === $this->sales['id']);

    app(SyncUserRolesAction::class)->execute($this->target, [], []);
});

it('removes a role whose scope became team after it was assigned tenant-wide without failing', function (): void {
    $this->assignments = [['id' => ModelStub::ulid('lead-tenant-wide'), 'role_id' => $this->leadRole['id'], 'model_type' => 'user', 'model_id' => $this->target->getKey(), 'scope_type' => $this->tenantMorph, 'scope_id' => $this->tenant->getKey()]];
    $this->assignRole->shouldReceive('removeAssignment')->once()
        ->withArgs(fn (User $user, RoleAssignment $assignment): bool => $assignment->scope_type === $this->tenantMorph);

    app(SyncUserRolesAction::class)->execute($this->target, []);
});

it('leaves the team-scoped roles of a user alone when only the tenant-wide roles are synced', function (): void {
    $this->assignments = [['id' => ModelStub::ulid('lead-in-sales'), 'role_id' => $this->leadRole['id'], 'model_type' => 'user', 'model_id' => $this->target->getKey(), 'scope_type' => $this->teamMorph, 'scope_id' => $this->sales['id']]];
    $this->assignRole->shouldReceive('assign')->once()
        ->withArgs(fn (User $user, Role $role, mixed $scope): bool => $role->getKey() === $this->editorRole['id'] && $scope?->getKey() === $this->tenant->getKey());
    $this->assignRole->shouldNotReceive('removeAssignment');

    app(SyncUserRolesAction::class)->execute($this->target, [$this->editorRole['id']]);
});

it('refuses to hand out a tenant-wide role as a team role', function (): void {
    $this->assignRole->shouldNotReceive('assign');

    expect(fn () => app(SyncUserRolesAction::class)->execute($this->target, [], [['role_id' => $this->editorRole['id'], 'team_id' => $this->sales['id']]]))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['team_roles' => ['Nur Rollen mit dem Geltungsbereich Team können für ein Team vergeben werden.']]);
        });
});

it('refuses an actor to assign a team role in a team outside their management reach', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'team-manager'));
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('other-team')]);
    GateSpy::allowing('assign');
    $this->assignRole->shouldNotReceive('assign');

    expect(fn () => app(SyncUserRolesAction::class)->execute($this->target, [], [($this->teamRole)($this->sales['id'])]))
        ->toThrow(AuthorizationException::class);
});

it('refuses an actor to assign a team role they may not assign', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'team-manager'));
    FakeManagedUserResolver::reachingTheTenant();
    $gate = GateSpy::allowing();
    $this->assignRole->shouldNotReceive('assign');

    expect(fn () => app(SyncUserRolesAction::class)->execute($this->target, [], [($this->teamRole)($this->sales['id'])]))
        ->toThrow(AuthorizationException::class)
        ->and($gate->wasAskedFor('assign'))->toBeTrue();
});

it('lets an actor within reach assign a team role', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'team-manager'));
    FakeManagedUserResolver::reachingTeams([$this->sales['id']]);
    GateSpy::allowing('assign');
    $this->assignRole->shouldReceive('assign')->once();

    app(SyncUserRolesAction::class)->execute($this->target, [], [($this->teamRole)($this->sales['id'])]);
});
