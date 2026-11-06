<?php

declare(strict_types=1);

use App\Actions\Users\UpdateManagedUserAction;
use App\Models\Role;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    $this->memberships = new FakeTeamMembershipDirectory;
    app()->instance(TeamMembershipDirectory::class, $this->memberships);

    $this->actor = RoleHolder::make([
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'chefin@example.test',
    ], [], 'actor');

    AccessContext::actAs($this->actor);

    $this->target = RoleHolder::make([
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'mitarbeiter@example.test',
    ], [], 'target');

    $this->action = fn (): UpdateManagedUserAction => app(UpdateManagedUserAction::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a new address without the password right', function (): void {
    AccessContext::grant();
    $gate = GateSpy::allowing();

    expect(fn () => ($this->action)()->execute($this->actor, $this->target, [
        'first_name' => 'Neu',
        'email' => 'neu@example.test',
    ]))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['updatePassword'])
        ->and($gate->calls[0]['arguments'])->toBe([$this->target]);
});

it('lets the address through once the password right is granted', function (): void {
    AccessContext::grant();
    $gate = GateSpy::allowing('updatePassword');

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->execute($this->actor, $this->target, [
        'email' => 'neu@example.test',
    ]));

    expect($reached)->toBeTrue()
        ->and($gate->wasAskedFor('updatePassword'))->toBeTrue();
});

it('asks for nothing when the submitted address only differs in case and spacing', function (): void {
    AccessContext::grant();
    $gate = GateSpy::allowing();

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->execute($this->actor, $this->target, [
        'email' => '  MITARBEITER@example.test ',
    ]));

    expect($reached)->toBeTrue()
        ->and($gate->abilities())->toBeEmpty();
});

it('refuses the acting user to widen their own roles', function (): void {
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
    ]);

    FakePresenceVerifier::holding(['roles' => [(string) $role->getKey()]]);
    AccessContext::grant();
    GateSpy::allowing('assign');

    $this->directory->withRoles([$role])->withAssignedRoles($this->actor, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->actor, [
        'role_ids' => [(string) $role->getKey()],
    ]))->toThrow(ValidationException::class);
});

it('refuses the acting user to change their own teams', function (): void {
    AccessContext::grant();
    GateSpy::allowing();

    $this->memberships->withTeams($this->actor, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->actor, [
        'team_ids' => [ModelStub::ulid('another-team')],
    ]))->toThrow(ValidationException::class);
});

it('refuses the acting user to lift their own denied permissions', function (): void {
    AccessContext::grant();
    GateSpy::allowing();

    $this->directory->withDeniedPermissions($this->actor, [ModelStub::ulid('members.password')]);

    expect(fn () => ($this->action)()->execute($this->actor, $this->actor, [
        'denied_permission_ids' => [],
    ]))->toThrow(ValidationException::class);
});

it('demands the assign ability for every role a change touches', function (): void {
    $added = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('added'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'added',
    ]);

    FakePresenceVerifier::holding(['roles' => [(string) $added->getKey()]]);
    AccessContext::grant();
    $gate = GateSpy::allowing();

    $this->directory->withRoles([$added])->withAssignedRoles($this->target, []);

    expect(fn () => ($this->action)()->execute($this->actor, $this->target, [
        'role_ids' => [(string) $added->getKey()],
    ]))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['assign']);
});

it('leaves an unchanged role list alone', function (): void {
    $held = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('held'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'held',
    ]);

    FakePresenceVerifier::holding(['roles' => [(string) $held->getKey()]]);
    AccessContext::grant();
    $gate = GateSpy::allowing();

    $this->directory->withRoles([$held])->withAssignedRoles($this->target, [(string) $held->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->execute($this->actor, $this->target, [
        'role_ids' => [(string) $held->getKey()],
    ]));

    expect($reached)->toBeTrue()
        ->and($gate->abilities())->toBeEmpty();
});
