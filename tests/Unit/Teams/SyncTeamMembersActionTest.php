<?php

declare(strict_types=1);

use App\Actions\Teams\SyncTeamMembersAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\FakeTenantUserIdResolver;
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

    /** @var callable(string):Team */
    $this->team = fn (string $seed): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'ancestor_team_ids' => [],
        'descendant_team_ids' => [],
    ]);

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'actor');
    AccessContext::actAs($this->actor);

    $this->member = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'member');

    $this->action = fn (): SyncTeamMembersAction => app(SyncTeamMembersAction::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects a member id that is not a ulid', function (): void {
    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    expect(fn () => ($this->action)()->syncMembersOfTeam($this->actor, ($this->team)('sales'), ['nonsense']))
        ->toThrow(ValidationException::class);
});

it('drops a user the tenant does not own before anything is written', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([]);
    $resolver = FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    $this->memberships->withMembers($team, []);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) ModelStub::ulid('foreign-user')],
    ));

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBeEmpty();
});

it('refuses to change the membership of a team outside the reach of the actor', function (): void {
    $team = ($this->team)('foreign-team');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    AccessContext::grant();

    $this->memberships->withMembers($team, []);

    expect(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) $this->member->getKey()],
    ))->toThrow(AuthorizationException::class);
});

it('refuses the acting user to add themselves to a team', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([(string) $this->actor->getKey()]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    $this->memberships->withMembers($team, []);

    expect(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) $this->actor->getKey()],
    ))->toThrow(ValidationException::class);
});

it('asks for nothing when the submitted membership matches the stored one', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    $resolver = FakeManagedUserResolver::reachingTeams([]);
    AccessContext::grant();

    $this->memberships->withMembers($team, [(string) $this->member->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) $this->member->getKey()],
    ));

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBeEmpty();
});

it('refuses to move a user into a team outside the reach of the actor', function (): void {
    $team = ($this->team)('foreign-team');

    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    AccessContext::grant();

    $this->memberships
        ->withLivingTeams([(string) $team->getKey()])
        ->withTeams($this->member, []);

    expect(fn () => ($this->action)()->syncTeamsOfUser(
        $this->actor,
        $this->member,
        [(string) $team->getKey()],
        'members.update',
    ))->toThrow(AuthorizationException::class);
});

it('refuses to take a user out of a team whose roles exceed the actor', function (): void {
    $team = ($this->team)('own-team');
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('team-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'team-role',
    ], ['permissions' => ModelStub::collection(Permission::class, [[
        'id' => ModelStub::ulid('members.password'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'members.password',
    ]])]);

    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant('members.view');

    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);
    $this->memberships
        ->withLivingTeams([(string) $team->getKey()])
        ->withTeams($this->member, [(string) $team->getKey()]);

    expect(fn () => ($this->action)()->syncTeamsOfUser(
        $this->actor,
        $this->member,
        [],
        'members.update',
    ))->toThrow(AuthorizationException::class);
});

it('silently drops a team of another tenant instead of attaching it', function (): void {
    $foreign = ($this->team)('foreign-team');

    $resolver = FakeManagedUserResolver::reachingTeams([]);
    AccessContext::grant();

    $this->memberships->withLivingTeams([])->withTeams($this->member, []);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncTeamsOfUser(
        $this->actor,
        $this->member,
        [(string) $foreign->getKey()],
        'members.update',
    ));

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBeEmpty();
});
