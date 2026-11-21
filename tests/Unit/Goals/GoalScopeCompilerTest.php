<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Models\Goal;
use App\Models\Team;
use App\Support\Goals\GoalPeriodCalculator;
use App\Support\Goals\GoalScopeCompiler;
use App\Support\Goals\GoalTargetTeamResolver;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;
use Tests\Unit\Goals\Doubles\StaticGoalTargetTeamResolver;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);

    $this->tenant = AccessContext::tenant();
    $this->targetTeams = new StaticGoalTargetTeamResolver;

    app()->instance(GoalTargetTeamResolver::class, $this->targetTeams);

    /** @var callable(array<string, mixed>):Goal */
    $this->goal = fn (array $attributes): Goal => ModelStub::make(Goal::class, [
        'tenant_id' => $this->tenant->getKey(),
        'scope_field_key' => 'owner_id',
        'period_field_key' => null,
        'includes_subteams' => false,
        ...$attributes,
    ]);

    /** @var callable(string, bool, list<string>):Team */
    $this->team = fn (string $seed, bool $register = true, array $descendants = []): Team => tap(
        ModelStub::make(Team::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'ancestor_team_ids' => [],
            'descendant_team_ids' => $descendants,
        ]),
        function (Team $team) use ($register): void {
            if ($register) {
                $this->targetTeams->with($team);
            }
        },
    );

    /** @var callable(string, FieldType, bool):FieldDefinition */
    $this->field = static fn (string $key, FieldType $type, bool $isFilterable = true): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        ['key' => $key, 'field_type' => $type, 'is_filterable' => $isFilterable],
    );

    /** @var callable(string):array{start: CarbonImmutable, end: CarbonImmutable} */
    $this->bounds = static fn (string $moment): array => app(GoalPeriodCalculator::class)->boundsFor(
        GoalPeriodType::Month,
        new CarbonImmutable($moment),
    );

    /** @var callable(array<string, mixed>, Goal, ?array<string, CarbonImmutable>):array<string, mixed> */
    $this->compile = static fn (array $definition, Goal $goal, ?array $bounds = null): array => app(GoalScopeCompiler::class)
        ->scopedDefinition($definition, $goal, $bounds);

    /** @var callable(array<string, mixed>):list<array<string, mixed>> */
    $this->conditions = static function (array $scoped): array {
        /** @var list<array<string, mixed>> $conditions */
        $conditions = array_values($scoped['filter_definition']['conditions']);

        return $conditions;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(GoalTargetTeamResolver::class);
});

it('restricts a personal goal to the target holder with a set comparison on the scope field', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal));

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]['field'])->toBe('owner_id')
        ->and($conditions[0]['operator'])->toBe('in')
        ->and($conditions[0]['value'])->toBe([ModelStub::ulid('target-user')]);
});

it('refuses a personal goal whose target holder is gone instead of counting the whole tenant', function (): void {
    $goal = ($this->goal)(['scope_type' => GoalScopeType::User, 'target_user_id' => null]);

    expect(fn (): array => ($this->compile)([], $goal))
        ->toThrow(ReportNotExecutableException::class);
});

it('restricts a team goal to the target team alone when the team tree is excluded', function (): void {
    $root = ($this->team)('root-team', true, [ModelStub::ulid('child-team')]);

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Team,
        'scope_field_key' => 'team_id',
        'target_team_id' => $root->getKey(),
        'includes_subteams' => false,
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal));

    expect($conditions[0]['field'])->toBe('team_id')
        ->and($conditions[0]['operator'])->toBe('in')
        ->and($conditions[0]['value'])->toBe([(string) $root->getKey()]);
});

it('widens a team goal to the whole team tree once the subteams are included', function (): void {
    $root = ($this->team)('root-team', true, [ModelStub::ulid('child-team'), ModelStub::ulid('grandchild-team')]);

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Team,
        'scope_field_key' => 'team_id',
        'target_team_id' => $root->getKey(),
        'includes_subteams' => true,
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal));

    expect($conditions[0]['value'])->toBe([
        (string) $root->getKey(),
        ModelStub::ulid('child-team'),
        ModelStub::ulid('grandchild-team'),
    ]);
});

it('lists a team that is its own descendant only once', function (): void {
    $root = ($this->team)('root-team', true, [ModelStub::ulid('root-team'), ModelStub::ulid('child-team')]);

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Team,
        'scope_field_key' => 'team_id',
        'target_team_id' => $root->getKey(),
        'includes_subteams' => true,
    ]);

    expect(($this->conditions)(($this->compile)([], $goal))[0]['value'])->toBe([
        (string) $root->getKey(),
        ModelStub::ulid('child-team'),
    ]);
});

it('refuses a team goal whose target team is unreachable instead of widening the scope', function (): void {
    ($this->team)('foreign-team', false);

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Team,
        'scope_field_key' => 'team_id',
        'target_team_id' => ModelStub::ulid('foreign-team'),
    ]);

    expect(fn (): array => ($this->compile)([], $goal))
        ->toThrow(ReportNotExecutableException::class)
        ->and($this->targetTeams->askedFor)->toBe([ModelStub::ulid('foreign-team')]);
});

it('looks the target team up inside the tenant of the goal and never by id alone', function (): void {
    app()->forgetInstance(GoalTargetTeamResolver::class);

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Team,
        'scope_field_key' => 'team_id',
        'target_team_id' => ModelStub::ulid('root-team'),
    ]);

    $shape = QueryShape::attemptedBy(fn (): array => ($this->compile)([], $goal));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('teams', ModelStub::ulid('root-team')))->toBeTrue();
});

it('hands a tenant goal the report definition completely unchanged', function (): void {
    $definition = ['filter_definition' => ['combinator' => 'or', 'conditions' => [['field' => 'stage']]]];

    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Tenant,
        'scope_field_key' => null,
    ]);

    expect(($this->compile)($definition, $goal))->toBe($definition);
});

it('appends the restriction to an and root instead of wrapping the tree', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
    ]);

    $scoped = ($this->compile)([
        'filter_definition' => ['combinator' => 'and', 'conditions' => [['field' => 'stage']]],
    ], $goal);

    expect($scoped['filter_definition']['combinator'])->toBe('and')
        ->and(($this->conditions)($scoped))->toHaveCount(2)
        ->and(($this->conditions)($scoped)[0])->toBe(['field' => 'stage'])
        ->and(($this->conditions)($scoped)[1]['field'])->toBe('owner_id');
});

it('wraps an or root in a new and root so the restriction is never an alternative', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
    ]);

    $original = ['combinator' => 'or', 'conditions' => [['field' => 'stage'], ['field' => 'owner_id']]];

    $scoped = ($this->compile)(['filter_definition' => $original], $goal);
    $conditions = ($this->conditions)($scoped);

    expect($scoped['filter_definition']['combinator'])->toBe('and')
        ->and($conditions)->toHaveCount(2)
        ->and($conditions[0])->toBe($original)
        ->and($conditions[1]['field'])->toBe('owner_id');
});

it('produces a complete and node when the report carries no filter at all', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
    ]);

    $scoped = ($this->compile)([], $goal);

    expect($scoped['filter_definition']['combinator'])->toBe('and')
        ->and($scoped['filter_definition']['conditions'])->toHaveCount(1);
});

it('adds one range node for the period field, serialised in the report time zone and inclusive of the last day', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
        'period_field_key' => 'closed_on',
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal, ($this->bounds)('2026-05-15T09:00:00Z')));

    expect($conditions)->toHaveCount(2)
        ->and($conditions[1]['field'])->toBe('closed_on')
        ->and($conditions[1]['operator'])->toBe('inRange')
        ->and($conditions[1]['value'])->toBe('2026-05-01')
        ->and($conditions[1]['valueTo'])->toBe('2026-05-31')
        ->and($conditions[1])->not->toHaveKey('conditions');
});

it('takes a snapshot without a period node when the goal names no period field', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('target-user'),
        'period_field_key' => null,
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal, ($this->bounds)('2026-05-15T09:00:00Z')));

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]['field'])->toBe('owner_id');
});

it('restricts a tenant goal by its period field although the scope stays the whole tenant', function (): void {
    $goal = ($this->goal)([
        'scope_type' => GoalScopeType::Tenant,
        'scope_field_key' => null,
        'period_field_key' => 'closed_on',
    ]);

    $conditions = ($this->conditions)(($this->compile)([], $goal, ($this->bounds)('2026-05-15T09:00:00Z')));

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]['field'])->toBe('closed_on')
        ->and($conditions[0]['operator'])->toBe('inRange');
});

it('accepts a filterable single select as a restriction field and refuses what cannot express a set comparison', function (): void {
    $compiler = app(GoalScopeCompiler::class);

    expect($compiler->supportsScopeField(($this->field)('pipeline', FieldType::SingleSelect)))->toBeTrue()
        ->and($compiler->supportsScopeField(($this->field)('hidden_pipeline', FieldType::SingleSelect, false)))->toBeFalse()
        ->and($compiler->supportsScopeField(($this->field)('linked_records', FieldType::RelationHasMany)))->toBeFalse()
        ->and($compiler->supportsScopeField(($this->field)('tags', FieldType::MultiSelect)))->toBeFalse();
});
