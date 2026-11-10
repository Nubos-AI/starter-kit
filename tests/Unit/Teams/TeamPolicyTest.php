<?php

declare(strict_types=1);

use App\Actions\Teams\BulkDeleteTeamsAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Policies\Teams\TeamPolicy;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\FakeTeamMembershipDirectory;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = ModelStub::make(StaticAuthorityUser::class, ['id' => ModelStub::ulid('team-lead'), 'tenant_id' => $this->tenant->getKey(), 'status' => UserStatus::Accepted->value]);

    $this->team = fn (string $seed): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
    ]);

    $this->policy = fn (): TeamPolicy => app(TeamPolicy::class);

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

    $this->nestedTeam = fn (): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'parent_team_id' => ModelStub::ulid('company'),
        'ancestor_team_ids' => [ModelStub::ulid('company')],
        'descendant_team_ids' => [],
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a team-scoped manager to change, delete or move a team outside their reach', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    $foreign = ($this->team)('foreign-team');
    $policy = ($this->policy)();

    expect($policy->update($this->actor, $foreign))->toBeFalse()
        ->and($policy->delete($this->actor, $foreign))->toBeFalse()
        ->and($policy->reparent($this->actor, $foreign))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['teams.update', 'teams.delete', 'teams.reparent']);
});

it('lets a team-scoped manager change, delete and move a team within their reach', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    $own = ($this->team)('own-team');
    $policy = ($this->policy)();

    expect($policy->update($this->actor, $own))->toBeTrue()
        ->and($policy->delete($this->actor, $own))->toBeTrue()
        ->and($policy->reparent($this->actor, $own))->toBeTrue();
});

it('lets a tenant-wide holder change, delete and move every team of the tenant', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    $team = ($this->team)('any-team');
    $policy = ($this->policy)();

    expect($policy->update($this->actor, $team))->toBeTrue()
        ->and($policy->delete($this->actor, $team))->toBeTrue()
        ->and($policy->reparent($this->actor, $team))->toBeTrue();
});

it('refuses every team of another tenant even to a tenant-wide holder', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    $foreign = ModelStub::make(Team::class, ['id' => ModelStub::ulid('globex-team'), 'tenant_id' => ModelStub::ulid('globex'), 'name' => 'globex']);
    $policy = ($this->policy)();

    expect($policy->update($this->actor, $foreign))->toBeFalse()
        ->and($policy->delete($this->actor, $foreign))->toBeFalse()
        ->and($policy->reparent($this->actor, $foreign))->toBeFalse();
});

it('leaves a team outside the reach of the actor out of a bulk delete and keeps one within it', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    $action = app(BulkDeleteTeamsAction::class);
    $mayDelete = new ReflectionMethod($action, 'mayDelete');

    expect($mayDelete->invoke($action, $this->actor, ($this->team)('foreign-team')))->toBeFalse()
        ->and($mayDelete->invoke($action, $this->actor, ($this->team)('own-team')))->toBeTrue();
});

it('refuses the access rules of a team outside the reach of a team-scoped manager', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    $foreign = ($this->team)('foreign-team');
    $policy = ($this->policy)();

    expect($policy->viewAccessRules($this->actor, $foreign))->toBeFalse()
        ->and($policy->manageAccessRules($this->actor, $foreign))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['teams.manage', 'teams.manage']);
});

it('lets a manager view and change the access rules of a team within reach that does not restrict the manager', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('sub-team')]);
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid('own-team')]));
    $policy = ($this->policy)();

    expect($policy->viewAccessRules($this->actor, ($this->team)('sub-team')))->toBeTrue()
        ->and($policy->manageAccessRules($this->actor, ($this->team)('sub-team')))->toBeTrue();
});

it('refuses a manager without admin authority to change the access rules that restrict the manager', function (string $memberOf): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid($memberOf)]));
    $team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'descendant_team_ids' => json_encode([ModelStub::ulid('sales-north')]),
    ]);
    $policy = ($this->policy)();

    expect($policy->viewAccessRules($this->actor, $team))->toBeTrue()
        ->and($policy->manageAccessRules($this->actor, $team))->toBeFalse();
})->with([
    'member of the team itself' => ['sales'],
    'member of a sub team the rules reach down into' => ['sales-north'],
]);

it('lets an admin authority change the access rules of a team the admin belongs to', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    $admin = ModelStub::make(StaticAuthorityUser::class, ['id' => ModelStub::ulid('scope-admin'), 'tenant_id' => $this->tenant->getKey()]);
    $admin->escalated = true;
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($admin, [ModelStub::ulid('sales')]));

    expect(($this->policy)()->manageAccessRules($admin, ($this->team)('sales')))->toBeTrue();
});

it('refuses a manager without admin authority to move a team whose inherited access rules restrict the manager', function (string $memberOf): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid($memberOf)]));
    $team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'descendant_team_ids' => json_encode([ModelStub::ulid('sales-north')]),
    ]);

    expect(($this->policy)()->reparent($this->actor, $team))->toBeFalse();
})->with([
    'member of the team itself' => ['sales'],
    'member of a sub team that moves along' => ['sales-north'],
]);

it('lets an admin authority move a team the admin belongs to', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    $admin = ModelStub::make(StaticAuthorityUser::class, ['id' => ModelStub::ulid('scope-admin'), 'tenant_id' => $this->tenant->getKey()]);
    $admin->escalated = true;
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($admin, [ModelStub::ulid('sales')]));

    expect(($this->policy)()->reparent($admin, ($this->team)('sales')))->toBeTrue();
});

it('lets a manager receive a moved team below a team within reach even when the manager belongs to it', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team')]);
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid('own-team')]));
    $policy = ($this->policy)();

    expect($policy->adopt($this->actor, ($this->team)('own-team')))->toBeTrue()
        ->and($policy->adopt($this->actor, ($this->team)('foreign-team')))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['teams.reparent', 'teams.reparent']);
});

it('refuses a manager without admin authority to delete a team whose access rules restrict the manager', function (string $memberOf): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid($memberOf)]));
    $team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'descendant_team_ids' => json_encode([ModelStub::ulid('sales-north')]),
    ]);
    $action = app(BulkDeleteTeamsAction::class);
    $mayDelete = new ReflectionMethod($action, 'mayDelete');

    expect(($this->policy)()->delete($this->actor, $team))->toBeFalse()
        ->and($mayDelete->invoke($action, $this->actor, $team))->toBeFalse();
})->with([
    'member of the team itself' => ['sales'],
    'member of a sub team the rules reach down into' => ['sales-north'],
]);

it('lets an admin authority delete a team the admin belongs to', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    $admin = ModelStub::make(StaticAuthorityUser::class, ['id' => ModelStub::ulid('scope-admin'), 'tenant_id' => $this->tenant->getKey()]);
    $admin->escalated = true;
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($admin, [ModelStub::ulid('sales')]));

    expect(($this->policy)()->delete($admin, ($this->team)('sales')))->toBeTrue();
});

it('refuses a manager who may not manage access rules to delete a team whose own or inherited access rules are active', function (string $governedSeed): void {
    $resolver = FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)($governedSeed);
    $action = app(BulkDeleteTeamsAction::class);
    $mayDelete = new ReflectionMethod($action, 'mayDelete');

    expect(($this->policy)()->delete($this->actor, ($this->nestedTeam)()))->toBeFalse()
        ->and($mayDelete->invoke($action, $this->actor, ($this->nestedTeam)()))->toBeFalse()
        ->and($resolver->askedFor)->toContain('teams.manage');
})->with([
    'rules on the team itself' => ['sales'],
    'rules on a parent team' => ['company'],
]);

it('lets a manager who may manage access rules delete a team whose access rules are active', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)('sales', 'company');

    expect(($this->policy)()->delete($this->actor, ($this->nestedTeam)()))->toBeTrue();
});

it('lets a manager who may not manage access rules delete a team when no active access rule applies along its chain', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)('unrelated-team');

    expect(($this->policy)()->delete($this->actor, ($this->nestedTeam)()))->toBeTrue();
});

it('refuses a manager who may not manage access rules to move a team out of a parent chain with active access rules', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)('company');

    expect(($this->policy)()->reparent($this->actor, ($this->nestedTeam)()))->toBeFalse();
});

it('lets a manager who may not manage access rules move a team whose parent chain carries no active access rule', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->withoutReachFor('teams.manage');
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)('sales');

    expect(($this->policy)()->reparent($this->actor, ($this->nestedTeam)()))->toBeTrue();
});

it('lets a manager who may manage access rules move a team out of a parent chain with active access rules', function (): void {
    FakeManagedUserResolver::reachingTheTenant();
    app()->instance(TeamMembershipDirectory::class, new FakeTeamMembershipDirectory);
    ($this->governedBy)('company');

    expect(($this->policy)()->reparent($this->actor, ($this->nestedTeam)()))->toBeTrue();
});
