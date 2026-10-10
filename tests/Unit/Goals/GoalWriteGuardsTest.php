<?php

declare(strict_types=1);

use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Models\Goal;
use App\Models\Report;
use App\Models\User;
use App\Support\Goals\GoalDefinitionValidator;
use App\Support\Goals\GoalInputRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->definitionValidator = Mockery::mock(GoalDefinitionValidator::class);
    $this->definitionValidator->shouldReceive('validate')->andReturn(
        ModelStub::make(Report::class, ['id' => ModelStub::ulid('report'), 'tenant_id' => $this->tenant->getKey()]),
    );

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->input = fn (array $overrides = []): array => [
        'name' => 'Revenue goal',
        'report_id' => ModelStub::ulid('report'),
        'scope_type' => GoalScopeType::User->value,
        'target_user_id' => (string) $this->actor->getKey(),
        'target_team_id' => null,
        'period_type' => GoalPeriodType::Month->value,
        'direction' => GoalDirection::AtLeast->value,
        'target_value' => '1000',
        ...$overrides,
    ];

    $this->goal = ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('goal'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => (string) $this->actor->getKey(),
        'scope_type' => GoalScopeType::User,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to create a goal without the create right and never reaches the database', function (): void {
    GateSpy::allowing();

    $action = new CreateGoalAction($this->definitionValidator);

    expect(fn (): Goal => $action->execute($this->actor, ($this->input)()))
        ->toThrow(AuthorizationException::class);
});

it('rejects malformed input before it even asks the gate', function (): void {
    $spy = GateSpy::allowing('create');

    $action = new CreateGoalAction($this->definitionValidator);

    expect(fn (): Goal => $action->execute($this->actor, ['name' => 'Revenue goal']))
        ->toThrow(ValidationException::class)
        ->and($spy->calls)->toBe([]);
});

it('consults the definition validator and only then writes the goal', function (): void {
    GateSpy::allowing('create');

    $seen = [];

    $validator = Mockery::mock(GoalDefinitionValidator::class);
    $validator->shouldReceive('validate')->once()->andReturnUsing(
        function (User $actor, array $validated) use (&$seen): Report {
            $seen = $validated;

            return ModelStub::make(Report::class, ['tenant_id' => $this->tenant->getKey()]);
        },
    );

    $action = new CreateGoalAction($validator);

    expect(WriteAttempt::reachedTheDatabase(fn (): Goal => $action->execute($this->actor, ($this->input)())))
        ->toBeTrue()
        ->and($seen['report_id'])->toBe(ModelStub::ulid('report'))
        ->and($seen['scope_type'])->toBe(GoalScopeType::User->value);
});

it('refuses to update a goal without the update right before it validates anything', function (): void {
    GateSpy::allowing();

    $validator = Mockery::mock(GoalDefinitionValidator::class);
    $validator->shouldNotReceive('validate');

    $action = new UpdateGoalAction($validator);

    expect(fn (): Goal => $action->execute($this->actor, $this->goal, ($this->input)()))
        ->toThrow(AuthorizationException::class);
});

it('refuses to delete a goal without the delete right', function (): void {
    GateSpy::allowing();

    expect(fn () => (new DeleteGoalAction)->execute($this->actor, $this->goal))
        ->toThrow(AuthorizationException::class);
});

it('keeps only the target that matches the scope so the database invariant can never be broken', function (): void {
    $userGoal = GoalInputRules::attributes(($this->input)([
        'scope_type' => GoalScopeType::User->value,
        'target_user_id' => ModelStub::ulid('holder'),
        'target_team_id' => ModelStub::ulid('team'),
        'includes_subteams' => true,
    ]));

    $teamGoal = GoalInputRules::attributes(($this->input)([
        'scope_type' => GoalScopeType::Team->value,
        'target_user_id' => ModelStub::ulid('holder'),
        'target_team_id' => ModelStub::ulid('team'),
        'includes_subteams' => true,
    ]));

    $tenantGoal = GoalInputRules::attributes(($this->input)([
        'scope_type' => GoalScopeType::Tenant->value,
        'target_user_id' => ModelStub::ulid('holder'),
        'target_team_id' => ModelStub::ulid('team'),
        'includes_subteams' => true,
    ]));

    expect($userGoal['target_user_id'])->toBe(ModelStub::ulid('holder'))
        ->and($userGoal['target_team_id'])->toBeNull()
        ->and($userGoal['includes_subteams'])->toBeFalse()
        ->and($teamGoal['target_team_id'])->toBe(ModelStub::ulid('team'))
        ->and($teamGoal['target_user_id'])->toBeNull()
        ->and($teamGoal['includes_subteams'])->toBeTrue()
        ->and($tenantGoal['target_user_id'])->toBeNull()
        ->and($tenantGoal['target_team_id'])->toBeNull()
        ->and($tenantGoal['includes_subteams'])->toBeFalse();
});

it('derives the restriction field from the scope when the input names none', function (): void {
    expect(GoalInputRules::attributes(($this->input)(['scope_type' => GoalScopeType::User->value]))['scope_field_key'])
        ->toBe(SystemFilterField::Owner->value)
        ->and(GoalInputRules::attributes(($this->input)(['scope_type' => GoalScopeType::Team->value]))['scope_field_key'])
        ->toBe(SystemFilterField::Team->value)
        ->and(GoalInputRules::attributes(($this->input)(['scope_type' => GoalScopeType::Tenant->value]))['scope_field_key'])
        ->toBeNull();
});

it('keeps a restriction field the input named instead of overwriting it with the system one', function (): void {
    expect(GoalInputRules::attributes(($this->input)(['scope_field_key' => 'pipeline']))['scope_field_key'])
        ->toBe('pipeline');
});

it('reads a blank period or restriction field as none at all', function (): void {
    $attributes = GoalInputRules::attributes(($this->input)([
        'scope_field_key' => '',
        'period_field_key' => '',
    ]));

    expect($attributes['period_field_key'])->toBeNull()
        ->and($attributes['scope_field_key'])->toBe(SystemFilterField::Owner->value);
});

it('never carries the tenant or the owner in the attributes a caller can influence', function (): void {
    $attributes = GoalInputRules::attributes(($this->input)([
        'tenant_id' => ModelStub::ulid('other-tenant'),
        'owner_id' => ModelStub::ulid('other-owner'),
    ]));

    expect($attributes)->not->toHaveKey('tenant_id')
        ->and($attributes)->not->toHaveKey('owner_id');
});

it('demands a name, a report, a scope, a period, a direction and a numeric target', function (): void {
    expect(array_keys(GoalInputRules::rules()))->toBe([
        'name',
        'report_id',
        'scope_type',
        'target_user_id',
        'target_team_id',
        'includes_subteams',
        'scope_field_key',
        'period_field_key',
        'period_type',
        'direction',
        'target_value',
    ])
        ->and(GoalInputRules::rules()['name'])->toContain('required')
        ->and(GoalInputRules::rules()['report_id'])->toContain('required')
        ->and(GoalInputRules::rules()['target_value'])->toContain('numeric')
        ->and(GoalInputRules::rules()['target_user_id'])->toContain('nullable')
        ->and(GoalInputRules::rules()['target_team_id'])->toContain('nullable');
});
