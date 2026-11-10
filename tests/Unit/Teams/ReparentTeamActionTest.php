<?php

declare(strict_types=1);

use App\Actions\Teams\ReparentTeamAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Support\Authorization\RowAccess\TeamAccessRuleCoverage;
use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use App\Support\Authorization\TeamTreeMaterializer;
use App\Support\Teams\TeamInputValidator;
use App\Support\Teams\TeamMembershipDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
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
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant('acme');
    $this->actor = AccessContext::user($this->tenant, [], 'team-lead');

    $this->team = fn (string $seed): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
    ]);

    $this->materializer = Mockery::mock(TeamTreeMaterializer::class);
    $this->validator = Mockery::mock(TeamInputValidator::class)->makePartial();
    $this->validator->shouldReceive('parentOrFail')->andReturnUsing(fn (string $tenantId, string $parentId): Team => ($this->team)($parentId === ModelStub::ulid('foreign-parent') ? 'foreign-parent' : 'own-parent'));

    $this->action = fn (): ReparentTeamAction => new ReparentTeamAction($this->materializer, $this->validator, app(TeamAccessRuleCoverage::class));

    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($this->actor, [ModelStub::ulid('own-parent')]));

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

it('refuses to move a team under a parent outside the reach of the actor on the parent field', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team'), ModelStub::ulid('own-parent')]);
    $this->materializer->shouldNotReceive('attach');

    expect(fn () => ($this->action)()->execute($this->actor, ($this->team)('own-team'), ModelStub::ulid('foreign-parent')))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'parent_team_id' => [__('i18n.backend.actions.teams.reparent_team_action.the_parent_team_is_outside_your_reach')],
            ]);
        });
});

it('moves a team under a parent within the reach of the actor', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team'), ModelStub::ulid('own-parent')]);
    $this->materializer->shouldReceive('attach')->once()
        ->withArgs(static fn (Team $team, ?Team $parent): bool => $team->getKey() === ModelStub::ulid('own-team') && $parent?->getKey() === ModelStub::ulid('own-parent'));

    ($this->action)()->execute($this->actor, ($this->team)('own-team'), ModelStub::ulid('own-parent'));
});

it('refuses to move a team the actor belongs to so the actor cannot shed the access rules inherited from above', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team'), ModelStub::ulid('own-parent')]);
    $member = ModelStub::make(StaticAuthorityUser::class, ['id' => ModelStub::ulid('team-member'), 'tenant_id' => $this->tenant->getKey(), 'status' => UserStatus::Accepted->value]);
    app()->instance(TeamMembershipDirectory::class, (new FakeTeamMembershipDirectory)->withTeams($member, [ModelStub::ulid('own-team')]));
    $this->materializer->shouldNotReceive('attach');

    expect(fn () => ($this->action)()->execute($member, ($this->team)('own-team'), null))
        ->toThrow(AuthorizationException::class);
});

it('refuses to move a team below a parent whose chain carries access rules the actor may not manage on the parent field', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team'), ModelStub::ulid('own-parent')])->withoutReachFor('teams.manage');
    ($this->governedBy)('own-parent');
    $this->materializer->shouldNotReceive('attach');

    expect(fn () => ($this->action)()->execute($this->actor, ($this->team)('own-team'), ModelStub::ulid('own-parent')))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'parent_team_id' => [__('i18n.backend.support.authorization.row_access.team_access_rule_coverage.this_change_would_alter_access_rules_you_may_not_manage')],
            ]);
        });
});

it('moves a team below a parent with active access rules when the actor may manage the access rules of the team', function (): void {
    FakeManagedUserResolver::reachingTeams([ModelStub::ulid('own-team'), ModelStub::ulid('own-parent')]);
    ($this->governedBy)('own-parent');
    $this->materializer->shouldReceive('attach')->once();

    ($this->action)()->execute($this->actor, ($this->team)('own-team'), ModelStub::ulid('own-parent'));
});
