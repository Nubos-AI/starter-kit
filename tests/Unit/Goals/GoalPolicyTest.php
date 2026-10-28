<?php

declare(strict_types=1);

use App\Enums\Goals\GoalScopeType;
use App\Models\Goal;
use App\Models\Report;
use App\Models\Team;
use App\Policies\Goals\GoalPolicy;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('target-team'),
        'tenant_id' => $this->tenant->getKey(),
        'ancestor_team_ids' => [],
    ]);

    $this->subteam = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sub-team'),
        'tenant_id' => $this->tenant->getKey(),
        'ancestor_team_ids' => [ModelStub::ulid('target-team')],
    ]);

    /** @var callable(string, bool, list<Team>, ?string):StaticAuthorityUser */
    $this->user = function (string $seed, bool $escalated = false, array $teams = [], ?string $tenantId = null): StaticAuthorityUser {
        /** @var StaticAuthorityUser $user */
        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $tenantId ?? $this->tenant->getKey(),
        ], ['teams' => new EloquentCollection($teams)]);

        $user->escalated = $escalated;

        return $user;
    };

    /** @var callable(GoalScopeType, array<string, mixed>):Goal */
    $this->goal = fn (GoalScopeType $scopeType, array $attributes = []): Goal => ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('goal-'.$scopeType->value),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('creator'),
        'report_id' => (string) $this->report->getKey(),
        'scope_type' => $scopeType,
        ...$attributes,
    ], ['report' => $this->report]);

    $this->policy = fn (): GoalPolicy => app(GoalPolicy::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('hides a goal of another tenant before it asks anything else', function (): void {
    $spy = GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::User, [
        'tenant_id' => ModelStub::ulid('other-tenant'),
        'target_user_id' => ModelStub::ulid('creator'),
    ]);

    $creator = ($this->user)('creator', true);

    expect(($this->policy)()->view($creator, $goal))->toBeFalse()
        ->and($spy->wasAskedFor('view'))->toBeFalse();
});

it('hides a goal whose source report the caller may not view', function (): void {
    GateSpy::allowing();

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('holder')]);

    expect(($this->policy)()->view(($this->user)('holder'), $goal))->toBeFalse()
        ->and(($this->policy)()->view(($this->user)('creator', true), $goal))->toBeFalse();
});

it('shows a personal goal to its target holder and to its creator', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('holder')]);

    expect(($this->policy)()->view(($this->user)('holder'), $goal))->toBeTrue()
        ->and(($this->policy)()->view(($this->user)('creator'), $goal))->toBeTrue()
        ->and(($this->policy)()->view(($this->user)('stranger'), $goal))->toBeFalse();
});

it('shows a team goal to a direct member of the target team only', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::Team, ['target_team_id' => (string) $this->team->getKey()]);

    expect(($this->policy)()->view(($this->user)('member', false, [$this->team]), $goal))->toBeTrue()
        ->and(($this->policy)()->view(($this->user)('subteam-member', false, [$this->subteam]), $goal))->toBeFalse()
        ->and(($this->policy)()->view(($this->user)('stranger'), $goal))->toBeFalse();
});

it('keeps a goal on the whole tenant invisible without escalated authority, even for its creator', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::Tenant);

    expect(($this->policy)()->view(($this->user)('creator'), $goal))->toBeFalse()
        ->and(($this->policy)()->view(($this->user)('admin', true), $goal))->toBeTrue();
});

it('shows every scope of goal to an escalated authority of the same tenant', function (): void {
    GateSpy::allowing('view');

    $admin = ($this->user)('admin', true);

    expect(($this->policy)()->view($admin, ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('holder')])))->toBeTrue()
        ->and(($this->policy)()->view($admin, ($this->goal)(GoalScopeType::Team, ['target_team_id' => (string) $this->team->getKey()])))->toBeTrue()
        ->and(($this->policy)()->view($admin, ($this->goal)(GoalScopeType::Tenant)))->toBeTrue();
});

it('reserves governing a goal for its creator and for escalated authority, never for the target holder', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('holder')]);

    $holder = ($this->user)('holder');
    $creator = ($this->user)('creator');
    $admin = ($this->user)('admin', true);
    $policy = ($this->policy)();

    expect($policy->view($holder, $goal))->toBeTrue()
        ->and($policy->update($holder, $goal))->toBeFalse()
        ->and($policy->delete($holder, $goal))->toBeFalse()
        ->and($policy->update($creator, $goal))->toBeTrue()
        ->and($policy->delete($creator, $goal))->toBeTrue()
        ->and($policy->update($admin, $goal))->toBeTrue()
        ->and($policy->delete($admin, $goal))->toBeTrue();
});

it('refuses to govern a goal that is invisible in the first place', function (): void {
    GateSpy::allowing();

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('creator')]);
    $creator = ($this->user)('creator');

    expect(($this->policy)()->update($creator, $goal))->toBeFalse()
        ->and(($this->policy)()->delete($creator, $goal))->toBeFalse();
});

it('asks the gate for the view right on the source report of the goal', function (): void {
    $spy = GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => ModelStub::ulid('holder')]);

    ($this->policy)()->view(($this->user)('holder'), $goal);

    expect($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['ability'])->toBe('view')
        ->and($spy->calls[0]['arguments'][0])->toBe($this->report);
});

it('hides a goal whose source report is gone', function (): void {
    GateSpy::allowing('view');

    $goal = ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('orphan-goal'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('creator'),
        'scope_type' => GoalScopeType::User,
        'target_user_id' => ModelStub::ulid('creator'),
    ], ['report' => null]);

    expect(($this->policy)()->view(($this->user)('creator', true), $goal))->toBeFalse();
});

it('lets anybody open the list and the create form because the rows are filtered afterwards', function (): void {
    $policy = ($this->policy)();
    $stranger = ($this->user)('stranger');

    expect($policy->viewAny($stranger))->toBeTrue()
        ->and($policy->create($stranger))->toBeTrue();
});
