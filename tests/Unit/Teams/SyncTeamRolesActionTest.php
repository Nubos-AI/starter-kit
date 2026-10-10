<?php

declare(strict_types=1);

use App\Actions\Teams\SyncTeamRolesAction;
use App\Enums\Authorization\RoleAuthority;
use App\Exceptions\Authorization\EscalatedTeamRoleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Support\Authorization\AuthorizationDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    $this->team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales-team'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Vertrieb',
        'ancestor_team_ids' => [],
        'descendant_team_ids' => [],
    ]);

    /** @var callable(string, array<string, mixed>, list<string>):Role */
    $this->roleWith = function (string $seed, array $attributes = [], array $permissionNames = []): Role {
        $permissions = array_map(fn (string $name): Permission => ModelStub::make(Permission::class, [
            'id' => ModelStub::ulid($name),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $name,
        ]), $permissionNames);

        return ModelStub::make(Role::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'name' => $seed,
            ...$attributes,
        ], ['permissions' => ModelStub::collection(Permission::class, [])->merge($permissions)]);
    };

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'actor');
    AccessContext::actAs($this->actor);

    $this->action = fn (): SyncTeamRolesAction => app(SyncTeamRolesAction::class);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('rejects a role id that does not exist in the bound tenant', function (): void {
    FakePresenceVerifier::holding(['roles' => []]);
    AccessContext::grant('roles.update');

    expect(fn () => ($this->action)()->execute($this->actor, $this->team, [ModelStub::ulid('ghost')]))
        ->toThrow(ValidationException::class);
});

it('refuses a role carrying an authority on a team', function (): void {
    $role = ($this->roleWith)('escalating', ['authority' => RoleAuthority::SuperAdmin->value]);

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant('roles.update');
    GateSpy::allowing('assign');

    $this->directory->withRoles([$role])->withAssignedRoles($this->team, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->team, [(string) $role->getKey()]))
        ->toThrow(EscalatedTeamRoleException::class);
});

it('refuses a role the gate does not let the actor assign', function (): void {
    $role = ($this->roleWith)('sales');

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant();
    $gate = GateSpy::allowing();

    $this->directory->withRoles([$role])->withAssignedRoles($this->team, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->team, [(string) $role->getKey()]))
        ->toThrow(AuthorizationException::class)
        ->and($gate->wasAskedFor('assign'))->toBeTrue();
});

it('refuses a role whose permissions exceed the ones the actor holds', function (): void {
    $role = ($this->roleWith)('sales', [], ['members.password']);

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant('members.view');
    GateSpy::allowing('assign');

    $this->directory->withRoles([$role])->withAssignedRoles($this->team, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->team, [(string) $role->getKey()]))
        ->toThrow(AuthorizationException::class);
});

it('lets a role through whose permissions the actor holds', function (): void {
    $role = ($this->roleWith)('sales', [], ['members.view']);

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant('members.view');
    $gate = GateSpy::allowing('assign');

    $this->directory->withRoles([$role])->withAssignedRoles($this->team, []);

    $reached = WriteAttempt::reachedTheDatabase(
        fn () => ($this->action)()->execute($this->actor, $this->team, [(string) $role->getKey()]),
    );

    expect($reached)->toBeTrue()
        ->and($gate->wasAskedFor('assign'))->toBeTrue();
});

it('re-checks only the roles the team does not hold yet', function (): void {
    $held = ($this->roleWith)('held', [], ['members.password']);
    $added = ($this->roleWith)('added', [], ['members.view']);

    FakePresenceVerifier::holding(['roles' => [(string) $held->getKey(), (string) $added->getKey()]]);
    $resolver = AccessContext::grant('members.view');
    GateSpy::allowing('assign');

    $this->directory
        ->withRoles([$held, $added])
        ->withAssignedRoles($this->team, [(string) $held->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->execute(
        $this->actor,
        $this->team,
        [(string) $held->getKey(), (string) $added->getKey()],
    ));

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBe(['members.view']);
});

it('clears every role of a team whose submitted list is empty', function (): void {
    $this->directory->withRoles([])->withAssignedRoles($this->team, []);

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    ($this->action)()->execute($this->actor, $this->team, []);

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toStartWith('delete from "role_assignments"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain('');
});

it('keeps the submitted role out of the cleanup it runs first', function (): void {
    $role = ($this->roleWith)('sales', [], ['members.view']);

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant('members.view');
    GateSpy::allowing('assign');

    $this->directory->withRoles([$role])->withAssignedRoles($this->team, []);

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    ($this->action)()->execute($this->actor, $this->team, [(string) $role->getKey()]);

    expect($connection->writtenStatements[0]['sql'])->toStartWith('delete from "role_assignments"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain((string) $role->getKey())
        ->and($connection->writtenStatements[0]['bindings'])->not->toContain('')
        ->and($connection->writtenStatements[1]['sql'])->toStartWith('insert into "role_assignments"');
});
