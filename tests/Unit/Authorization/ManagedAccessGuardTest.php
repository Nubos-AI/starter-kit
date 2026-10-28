<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedAccessGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    /** @var callable(string):Team */
    $this->team = fn (string $seed): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'ancestor_team_ids' => [],
        'descendant_team_ids' => [],
    ]);

    /** @var callable(string, array<string, mixed>, list<Permission>):Role */
    $this->roleWith = function (string $seed, array $attributes = [], array $permissions = []): Role {
        return ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            ...$attributes,
        ], ['permissions' => ModelStub::collection(Permission::class, [])->merge($permissions)]);
    };

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'actor');
    AccessContext::actAs($this->actor);

    $this->guard = fn (): ManagedAccessGuard => app(ManagedAccessGuard::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks nothing when no team changes hands', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([]);
    AccessContext::grant();

    ($this->guard)()->assertMayChangeTeams($this->actor, [], 'members.update');

    expect($resolver->askedFor)->toBeEmpty();
});

it('refuses a team outside the management reach of the actor', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    AccessContext::grant();

    $foreign = ($this->team)('foreign-team');

    expect(fn () => ($this->guard)()->assertMayChangeTeams($this->actor, [(string) $foreign->getKey()], 'members.update'))
        ->toThrow(AuthorizationException::class);
});

it('accepts a team inside the reach and checks the roles that team carries', function (): void {
    $team = ($this->team)('own-team');
    FakeManagedUserResolver::reachingTeams([(string) $team->getKey()]);
    AccessContext::grant('members.view');

    $role = ($this->roleWith)('team-role', [], [ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid('members.view'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'members.view',
    ])]);

    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);

    ($this->guard)()->assertMayChangeTeams($this->actor, [(string) $team->getKey()], 'members.update');

    expect($this->directory->askedFor)->toContain('rolesAssignedToTeams');
});

it('refuses a team whose roles carry a permission the actor does not hold', function (): void {
    $team = ($this->team)('own-team');
    FakeManagedUserResolver::reachingTeams([(string) $team->getKey()]);
    AccessContext::grant('members.view');

    $role = ($this->roleWith)('team-role', [], [ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid('members.password'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'members.password',
    ])]);

    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);

    expect(fn () => ($this->guard)()->assertMayChangeTeams($this->actor, [(string) $team->getKey()], 'members.update'))
        ->toThrow(AuthorizationException::class);
});

it('refuses a team whose roles carry an authority the actor lacks', function (): void {
    $team = ($this->team)('own-team');
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    $role = ($this->roleWith)('escalating', ['authority' => RoleAuthority::ScopeAdmin->value]);

    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);

    expect(fn () => ($this->guard)()->assertMayChangeTeams($this->actor, [(string) $team->getKey()], 'members.update'))
        ->toThrow(AuthorizationException::class);
});

it('lets a tenant wide manager reach every team', function (): void {
    $team = ($this->team)('any-team');
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    ($this->guard)()->assertMayChangeTeams($this->actor, [(string) $team->getKey()], 'members.update');
})->throwsNoExceptions();

it('refuses the acting user to change their own access', function (): void {
    expect(fn () => ($this->guard)()->assertOwnAccessUnchanged(
        $this->actor,
        $this->actor,
        [ModelStub::ulid('role-a')],
        [ModelStub::ulid('role-a'), ModelStub::ulid('role-b')],
        'role_ids',
    ))->toThrow(ValidationException::class);
});

it('lets the acting user resend their own unchanged access in any order', function (): void {
    ($this->guard)()->assertOwnAccessUnchanged(
        $this->actor,
        $this->actor,
        [ModelStub::ulid('role-a'), ModelStub::ulid('role-b')],
        [ModelStub::ulid('role-b'), ModelStub::ulid('role-a')],
        'role_ids',
    );
})->throwsNoExceptions();

it('leaves the access of another account to the ordinary permission checks', function (): void {
    $other = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'other');

    ($this->guard)()->assertOwnAccessUnchanged(
        $this->actor,
        $other,
        [],
        [ModelStub::ulid('role-a')],
        'role_ids',
    );
})->throwsNoExceptions();

it('names the submitted field so the refusal lands on the form control', function (): void {
    try {
        ($this->guard)()->assertOwnAccessUnchanged(
            $this->actor,
            $this->actor,
            [],
            [ModelStub::ulid('permission-a')],
            'denied_permission_ids',
        );
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['denied_permission_ids']);

        return;
    }

    throw new RuntimeException('The guard accepted a change to the own access.');
});
