<?php

declare(strict_types=1);

use App\Actions\Teams\SyncTeamMembersAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Users\ManageableUserDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\FakeTenantUserIdResolver;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticManageableUserDirectory;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
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

    $this->users = new StaticManageableUserDirectory;
    app()->instance(ManageableUserDirectory::class, $this->users);

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

    $this->nestedTeam = fn (): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'parent_team_id' => ModelStub::ulid('company'),
        'ancestor_team_ids' => [ModelStub::ulid('company')],
        'descendant_team_ids' => [],
    ]);

    /** @var callable(string...):void */
    $this->governedBy = function (string ...$seeds): void {
        app()->instance(TeamAccessRuleSource::class, new StaticTeamAccessRuleSource(new EloquentCollection(array_map(
            fn (string $seed): TeamRecordAccessRule => ModelStub::make(TeamRecordAccessRule::class, [
                'id' => ModelStub::ulid("rule-{$seed}"),
                'tenant_id' => $this->tenant->getKey(),
                'team_id' => ModelStub::ulid($seed),
                'object_type_id' => ModelStub::ulid('companies'),
                'is_active' => true,
                'inheritance' => TeamAccessRuleInheritance::Intersect->value,
                'filter_definition' => ['combinator' => 'and', 'conditions' => [['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '1']]],
            ]),
            $seeds,
        ))));
    };

    ($this->governedBy)();
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

    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        ($this->action)()->syncMembersOfTeam($this->actor, $team, [(string) ModelStub::ulid('foreign-user')]);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($connection->openedTransactions)->toBeGreaterThan(0)
        ->and(collect($connection->writtenStatements)->flatMap(static fn (array $statement): array => $statement['bindings'])->all())
        ->not->toContain((string) ModelStub::ulid('foreign-user'))
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

it('refuses the acting user to join a team on their own', function (): void {
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

it('lets the acting user add someone else to a team whose roles stay within their own rights', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->member->getKey()]);
    AccessContext::grant();

    $this->memberships->withMembers($team, []);
    $this->users->withAccounts($this->member);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) $this->member->getKey()],
    ));

    expect($reached)->toBeTrue();
});

it('refuses to add someone to a team whose roles exceed the rights of the actor', function (): void {
    $team = ($this->team)('sales');
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('team-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'team-role',
    ], ['permissions' => ModelStub::collection(Permission::class, [[
        'id' => ModelStub::ulid('members.password'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'members.password',
    ]])]);

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant('members.view');

    $this->directory->withRoles([$role])->withTeamRoles($team, [(string) $role->getKey()]);
    $this->memberships->withMembers($team, []);

    expect(fn () => ($this->action)()->syncMembersOfTeam(
        $this->actor,
        $team,
        [(string) $this->member->getKey()],
    ))->toThrow(AuthorizationException::class);
});

it('refuses to take an account holding rights beyond the actor out of a team and names the member field', function (): void {
    $team = ($this->team)('sales');
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('admin-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'admin-role',
    ], ['permissions' => ModelStub::collection(Permission::class, [[
        'id' => ModelStub::ulid('roles.update'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'roles.update',
    ]])]);

    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant('members.view');

    $this->directory->withRoles([$role]);
    $this->users->withRoles($this->member, [(string) $role->getKey()]);
    $this->memberships->withMembers($team, [(string) $this->member->getKey()]);

    $refusal = null;

    try {
        ($this->action)()->syncMembersOfTeam($this->actor, $team, []);
    } catch (ValidationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal?->errors())->toHaveKey('member_ids');
});

it('lets the actor take an account out of a team whose rights it holds itself', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    $this->memberships->withMembers($team, [(string) $this->member->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $team, []));

    expect($reached)->toBeTrue();
});

it('refuses the acting user to leave a team on their own', function (): void {
    $team = ($this->team)('sales');

    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();

    $this->memberships->withMembers($team, [(string) $this->actor->getKey()]);

    expect(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $team, []))
        ->toThrow(ValidationException::class);
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

    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [(string) $foreign->getKey()], 'members.update');
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($connection->openedTransactions)->toBeGreaterThan(0)
        ->and(collect($connection->writtenStatements)->flatMap(static fn (array $statement): array => $statement['bindings'])->all())
        ->not->toContain((string) $foreign->getKey())
        ->and($resolver->askedFor)->toBeEmpty();
});

it('refuses to add a user outside the management reach of the actor to a team and names the member field', function (): void {
    $team = ($this->team)('own-team');
    $outsider = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'outsider');

    FakeTenantUserIdResolver::knowing([(string) $outsider->getKey()]);
    $resolver = FakeManagedUserResolver::reachingTeams([(string) $team->getKey()])->managing([]);
    AccessContext::grant();

    $this->memberships->withMembers($team, []);
    $this->users->withAccounts($outsider);

    $refusal = null;
    $reached = WriteAttempt::reachedTheDatabase(function () use ($team, $outsider, &$refusal): void {
        try {
            ($this->action)()->syncMembersOfTeam($this->actor, $team, [(string) $outsider->getKey()]);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($reached)->toBeFalse()
        ->and($refusal?->errors())->toBe([
            'member_ids' => [__('i18n.backend.support.authorization.managed_access_guard.a_selected_person_is_outside_your_management_reach')],
        ])
        ->and($resolver->askedFor)->toContain('members.update');
});

it('lets a team-scoped actor add a user who already lies within the reach of the actor', function (): void {
    $team = ($this->team)('own-team');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTeams([(string) $team->getKey()])->managing([(string) $this->member->getKey()]);
    AccessContext::grant();

    $this->memberships->withMembers($team, []);
    $this->users->withAccounts($this->member);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $team, [(string) $this->member->getKey()]));

    expect($reached)->toBeTrue();
});

it('refuses to take a member out of a team with active access rules along its chain when the actor may not manage them', function (string $governedSeed): void {
    $team = ($this->nestedTeam)();

    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    AccessContext::grant();
    ($this->governedBy)($governedSeed);

    $this->memberships->withMembers($team, [(string) $this->member->getKey()]);

    $refusal = null;
    $reached = WriteAttempt::reachedTheDatabase(function () use ($team, &$refusal): void {
        try {
            ($this->action)()->syncMembersOfTeam($this->actor, $team, []);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($reached)->toBeFalse()
        ->and($refusal?->errors())->toBe([
            'member_ids' => [__('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage')],
        ]);
})->with([
    'rules on the team itself' => ['sales'],
    'rules on a parent team' => ['company'],
]);

it('lets an actor who may manage access rules take a member out of a team with active access rules', function (): void {
    $team = ($this->nestedTeam)();

    FakeTenantUserIdResolver::knowing([]);
    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();
    ($this->governedBy)('company');

    $this->memberships->withMembers($team, [(string) $this->member->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $team, []));

    expect($reached)->toBeTrue();
});

it('refuses to take a user out of a team with active access rules when the actor may not manage them and names the team field', function (): void {
    $team = ($this->nestedTeam)();

    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    AccessContext::grant();
    ($this->governedBy)('company');

    $this->memberships
        ->withLivingTeams([(string) $team->getKey()])
        ->withTeamModels($team)
        ->withTeams($this->member, [(string) $team->getKey()]);

    $refusal = null;
    $reached = WriteAttempt::reachedTheDatabase(function () use (&$refusal): void {
        try {
            ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [], 'members.update');
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($reached)->toBeFalse()
        ->and($refusal?->errors())->toBe([
            'team_ids' => [__('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage')],
        ]);
});

it('lets an actor who may manage access rules take a user out of a team with active access rules', function (): void {
    $team = ($this->nestedTeam)();

    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();
    ($this->governedBy)('company');

    $this->memberships
        ->withLivingTeams([(string) $team->getKey()])
        ->withTeamModels($team)
        ->withTeams($this->member, [(string) $team->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [], 'members.update'));

    expect($reached)->toBeTrue();
});

it('refuses to add a user restricted by access rules to a team whose chain would lift them when the actor may not manage access rules', function (): void {
    $restricted = ($this->team)('restricted');
    $open = ($this->team)('open');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage')->managing([(string) $this->member->getKey()]);
    AccessContext::grant();
    ($this->governedBy)('restricted');

    $this->users->withAccounts($this->member)->withTeams($this->member, [(string) $restricted->getKey()]);
    $this->memberships->withMembers($open, [])->withTeamModels($restricted, $open);

    $refusal = null;
    $reached = WriteAttempt::reachedTheDatabase(function () use ($open, &$refusal): void {
        try {
            ($this->action)()->syncMembersOfTeam($this->actor, $open, [(string) $this->member->getKey()]);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($reached)->toBeFalse()
        ->and($refusal?->errors())->toBe([
            'member_ids' => [__('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage')],
        ]);
});

it('lets an actor who may manage access rules add a restricted user to a team whose chain would lift the rules', function (): void {
    $restricted = ($this->team)('restricted');
    $open = ($this->team)('open');

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->member->getKey()]);
    AccessContext::grant();
    ($this->governedBy)('restricted');

    $this->users->withAccounts($this->member)->withTeams($this->member, [(string) $restricted->getKey()]);
    $this->memberships->withMembers($open, [])->withTeamModels($restricted, $open);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $open, [(string) $this->member->getKey()]));

    expect($reached)->toBeTrue();
});

it('lets an actor who may not manage access rules add a restricted user to a team whose chain restricts the same object types', function (): void {
    $restricted = ($this->team)('restricted');
    $team = ($this->nestedTeam)();

    FakeTenantUserIdResolver::knowing([(string) $this->member->getKey()]);
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage')->managing([(string) $this->member->getKey()]);
    AccessContext::grant();
    ($this->governedBy)('restricted', 'company');

    $this->users->withAccounts($this->member)->withTeams($this->member, [(string) $restricted->getKey()]);
    $this->memberships->withMembers($team, [])->withTeamModels($restricted, $team);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncMembersOfTeam($this->actor, $team, [(string) $this->member->getKey()]));

    expect($reached)->toBeTrue();
});

it('refuses to move a restricted user into a team whose chain would lift the rules and names the team field', function (): void {
    $restricted = ($this->team)('restricted');
    $open = ($this->team)('open');

    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    AccessContext::grant();
    ($this->governedBy)('restricted');

    $this->memberships
        ->withLivingTeams([(string) $restricted->getKey(), (string) $open->getKey()])
        ->withTeamModels($restricted, $open)
        ->withTeams($this->member, [(string) $restricted->getKey()]);

    $refusal = null;
    $reached = WriteAttempt::reachedTheDatabase(function () use ($restricted, $open, &$refusal): void {
        try {
            ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [(string) $restricted->getKey(), (string) $open->getKey()], 'members.update');
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($reached)->toBeFalse()
        ->and($refusal?->errors())->toBe([
            'team_ids' => [__('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage')],
        ]);
});

it('lets an actor who may manage access rules move a restricted user into a team whose chain would lift the rules', function (): void {
    $restricted = ($this->team)('restricted');
    $open = ($this->team)('open');

    FakeManagedUserResolver::reachingTheTenant();
    AccessContext::grant();
    ($this->governedBy)('restricted');

    $this->memberships
        ->withLivingTeams([(string) $restricted->getKey(), (string) $open->getKey()])
        ->withTeamModels($restricted, $open)
        ->withTeams($this->member, [(string) $restricted->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [(string) $restricted->getKey(), (string) $open->getKey()], 'members.update'));

    expect($reached)->toBeTrue();
});

it('lets an actor who may not manage access rules move an unrestricted user into a team without access rules', function (): void {
    $plain = ($this->team)('plain');
    $open = ($this->team)('open');

    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    AccessContext::grant();
    ($this->governedBy)('restricted');

    $this->memberships
        ->withLivingTeams([(string) $plain->getKey(), (string) $open->getKey()])
        ->withTeamModels($plain, $open)
        ->withTeams($this->member, [(string) $plain->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(fn () => ($this->action)()->syncTeamsOfUser($this->actor, $this->member, [(string) $plain->getKey(), (string) $open->getKey()], 'members.update'));

    expect($reached)->toBeTrue();
});
