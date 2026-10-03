<?php

declare(strict_types=1);

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\TeamRecordAccessRule;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticTeamAccessRuleSource;
use Tests\Support\Doubles\StaticTeamChainProvider;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->companies = ModelStub::ulid('companies');
    $this->contacts = ModelStub::ulid('contacts');
    $this->parentTeam = ModelStub::ulid('parent-team');
    $this->childTeam = ModelStub::ulid('child-team');
    $this->siblingTeam = ModelStub::ulid('sibling-team');

    $this->member = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('member'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    /** @var callable(string, string, string, TeamAccessRuleInheritance):TeamRecordAccessRule */
    $this->rule = fn (
        string $teamId,
        string $objectTypeId,
        string $prefix,
        TeamAccessRuleInheritance $inheritance = TeamAccessRuleInheritance::Intersect,
    ): TeamRecordAccessRule => ModelStub::make(TeamRecordAccessRule::class, [
        'id' => ModelStub::ulid($teamId.$objectTypeId.$prefix),
        'tenant_id' => $this->tenant->getKey(),
        'team_id' => $teamId,
        'object_type_id' => $objectTypeId,
        'is_active' => true,
        'inheritance' => $inheritance->value,
        'filter_definition' => [
            'combinator' => 'and',
            'conditions' => [
                ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => $prefix],
            ],
        ],
    ]);

    /** @var callable(list<list<string>>, list<TeamRecordAccessRule>):TeamAccessRuleResolver */
    $this->resolver = fn (array $chains, array $rules): TeamAccessRuleResolver => new TeamAccessRuleResolver(
        new StaticTeamChainProvider($chains),
        new StaticTeamAccessRuleSource(new EloquentCollection($rules)),
    );

    /** @var callable(TeamRecordAccessRule):array<string, mixed> */
    $this->tree = static fn (TeamRecordAccessRule $rule): array => $rule->filter_definition;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('leaves an escalated authority unrestricted and never asks for the team chain', function (): void {
    $escalated = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('escalated'),
        'tenant_id' => $this->tenant->getKey(),
    ]);
    $escalated->escalated = true;

    $chains = new StaticTeamChainProvider([[$this->parentTeam]]);
    $source = new StaticTeamAccessRuleSource(new EloquentCollection([
        ($this->rule)($this->parentTeam, $this->companies, '76'),
    ]));

    $rules = (new TeamAccessRuleResolver($chains, $source))->resolveFor($escalated);

    expect($rules->isUnrestricted())->toBeTrue()
        ->and($chains->askedFor)->toBeEmpty()
        ->and($source->askedFor)->toBeEmpty();
});

it('leaves a user without any team membership unrestricted', function (): void {
    $rules = ($this->resolver)([], [
        ($this->rule)($this->parentTeam, $this->companies, '76'),
    ])->resolveFor($this->member);

    expect($rules->isUnrestricted())->toBeTrue();
});

it('leaves a user unrestricted when no active rule exists for any team in the chain', function (): void {
    $rules = ($this->resolver)([[$this->parentTeam]], [])->resolveFor($this->member);

    expect($rules->isUnrestricted())->toBeTrue();
});

it('narrows only the object types a rule actually names', function (): void {
    $rules = ($this->resolver)([[$this->parentTeam]], [
        ($this->rule)($this->parentTeam, $this->companies, '76'),
    ])->resolveFor($this->member);

    expect($rules->objectTypeIds())->toBe([$this->companies])
        ->and($rules->objectTypeIds())->not->toContain($this->contacts);
});

it('applies a parent rule to a child team that carries no rule of its own', function (): void {
    $parentRule = ($this->rule)($this->parentTeam, $this->companies, '76');

    $rules = ($this->resolver)([[$this->childTeam, $this->parentTeam]], [$parentRule])
        ->resolveFor($this->member);

    expect($rules->all()[$this->companies])->toBe([[($this->tree)($parentRule)]]);
});

it('intersects the child rule with the parent rule up the chain', function (): void {
    $childRule = ($this->rule)($this->childTeam, $this->companies, '761');
    $parentRule = ($this->rule)($this->parentTeam, $this->companies, '76');

    $rules = ($this->resolver)([[$this->childTeam, $this->parentTeam]], [$childRule, $parentRule])
        ->resolveFor($this->member);

    expect($rules->all()[$this->companies])->toBe([[
        ($this->tree)($childRule),
        ($this->tree)($parentRule),
    ]]);
});

it('cuts the parent chain once a child rule declares itself an override', function (): void {
    $childRule = ($this->rule)($this->childTeam, $this->companies, '80', TeamAccessRuleInheritance::Override);
    $parentRule = ($this->rule)($this->parentTeam, $this->companies, '76');

    $rules = ($this->resolver)([[$this->childTeam, $this->parentTeam]], [$childRule, $parentRule])
        ->resolveFor($this->member);

    expect($rules->all()[$this->companies])->toBe([[($this->tree)($childRule)]]);
});

it('keeps one alternative per team chain so memberships widen instead of narrow', function (): void {
    $childRule = ($this->rule)($this->childTeam, $this->companies, '76');
    $siblingRule = ($this->rule)($this->siblingTeam, $this->companies, '80');

    $rules = ($this->resolver)([[$this->childTeam], [$this->siblingTeam]], [$childRule, $siblingRule])
        ->resolveFor($this->member);

    expect($rules->all()[$this->companies])->toBe([
        [($this->tree)($childRule)],
        [($this->tree)($siblingRule)],
    ]);
});

it('drops an object type entirely when one of the chains carries no rule for it', function (): void {
    $rules = ($this->resolver)([[$this->childTeam], [$this->siblingTeam]], [
        ($this->rule)($this->childTeam, $this->companies, '76'),
    ])->resolveFor($this->member);

    expect($rules->isUnrestricted())->toBeTrue();
});

it('asks the rule source once for the flattened set of teams in every chain', function (): void {
    $source = new StaticTeamAccessRuleSource(new EloquentCollection([
        ($this->rule)($this->parentTeam, $this->companies, '76'),
    ]));

    $resolver = new TeamAccessRuleResolver(
        new StaticTeamChainProvider([[$this->childTeam, $this->parentTeam], [$this->parentTeam]]),
        $source,
    );

    $resolver->resolveFor($this->member);
    $resolver->resolveFor($this->member);

    expect($source->askedFor)->toBe([[$this->childTeam, $this->parentTeam]]);
});

it('resolves again after the memo was forgotten', function (): void {
    $source = new StaticTeamAccessRuleSource(new EloquentCollection([
        ($this->rule)($this->parentTeam, $this->companies, '76'),
    ]));

    $resolver = new TeamAccessRuleResolver(
        new StaticTeamChainProvider([[$this->parentTeam]]),
        $source,
    );

    $resolver->resolveFor($this->member);
    $resolver->forget();
    $resolver->resolveFor($this->member);

    expect($source->askedFor)->toHaveCount(2);
});
