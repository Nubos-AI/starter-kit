<?php

declare(strict_types=1);

use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use App\Http\Resources\Goals\GoalPeriodResource;
use App\Http\Resources\Goals\GoalResource;
use App\Models\Goal;
use App\Models\GoalPeriod;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Revenue',
        'object_type_id' => ModelStub::ulid('companies'),
    ]);

    $this->targetUser = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('holder'),
        'tenant_id' => $this->tenant->getKey(),
        'first_name' => 'Dana',
        'last_name' => 'Holder',
        'email' => 'dana@example.test',
    ]);

    $this->targetTeam = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('target-team'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Sales',
        'ancestor_team_ids' => [],
    ]);

    /** @var callable(GoalScopeType, array<string, mixed>, array<string, mixed>):Goal */
    $this->goal = fn (GoalScopeType $scopeType, array $attributes = [], array $relations = []): Goal => ModelStub::make(
        Goal::class,
        [
            'id' => ModelStub::ulid('goal'),
            'tenant_id' => $this->tenant->getKey(),
            'owner_id' => ModelStub::ulid('creator'),
            'report_id' => (string) $this->report->getKey(),
            'name' => 'Revenue goal',
            'scope_type' => $scopeType,
            'scope_field_key' => 'owner_id',
            'period_field_key' => 'closed_on',
            'period_type' => GoalPeriodType::Month,
            'direction' => GoalDirection::AtLeast,
            'target_value' => '1000.0000',
            'includes_subteams' => false,
            ...$attributes,
        ],
        ['targetUser' => null, 'targetTeam' => null, ...$relations],
    );

    /** @var callable(Goal):array<string, mixed> */
    $this->payload = function (Goal $goal): array {
        $request = Request::create('/goals', 'GET');
        $viewer = ModelStub::make(User::class, [
            'id' => ModelStub::ulid('viewer'),
            'tenant_id' => $this->tenant->getKey(),
        ]);

        $request->setUserResolver(static fn (): User => $viewer);

        /** @var array<string, mixed> $resolved */
        $resolved = (new GoalResource($goal))->resolve($request);

        return $resolved;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('names the target holder of a personal goal with e-mail and avatar instead of a raw id', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(
        GoalScopeType::User,
        ['target_user_id' => (string) $this->targetUser->getKey()],
        ['targetUser' => $this->targetUser],
    );

    expect(($this->payload)($goal)['target'])->toBe([
        'value' => (string) $this->targetUser->getKey(),
        'label' => 'Dana Holder',
        'description' => 'dana@example.test',
        'avatar' => ['name' => 'Dana Holder'],
    ]);
});

it('names the team of a team goal and gives it no avatar', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(
        GoalScopeType::Team,
        ['target_team_id' => (string) $this->targetTeam->getKey(), 'scope_field_key' => 'team_id'],
        ['targetTeam' => $this->targetTeam],
    );

    expect(($this->payload)($goal)['target'])->toBe([
        'value' => (string) $this->targetTeam->getKey(),
        'label' => 'Sales',
    ]);
});

it('carries no target at all for a goal on the whole tenant', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::Tenant, ['scope_field_key' => null]);

    expect(($this->payload)($goal)['target'])->toBeNull();
});

it('leaves the target empty when the named holder is gone instead of leaking the id as a label', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(
        GoalScopeType::User,
        ['target_user_id' => ModelStub::ulid('vanished')],
        ['targetUser' => null],
    );

    expect(($this->payload)($goal)['target'])->toBeNull()
        ->and(($this->payload)($goal)['target_user_id'])->toBe(ModelStub::ulid('vanished'));
});

it('tells a viewer who may see but not govern the goal why the actions are locked', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => (string) $this->targetUser->getKey()]);
    $payload = ($this->payload)($goal);

    expect($payload['can_update'])->toBeFalse()
        ->and($payload['can_delete'])->toBeFalse()
        ->and($payload['update_reason'])->toBe('not_owner')
        ->and($payload['delete_reason'])->toBe('not_owner');
});

it('tells a viewer who may not even see the goal that it is invisible', function (): void {
    GateSpy::allowing('update', 'delete');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => (string) $this->targetUser->getKey()]);
    $payload = ($this->payload)($goal);

    expect($payload['can_update'])->toBeFalse()
        ->and($payload['can_delete'])->toBeFalse()
        ->and($payload['update_reason'])->toBe('not_visible')
        ->and($payload['delete_reason'])->toBe('not_visible');
});

it('states no reason once the viewer may govern the goal', function (): void {
    GateSpy::allowing('view', 'update', 'delete');

    $goal = ($this->goal)(GoalScopeType::User, ['target_user_id' => (string) $this->targetUser->getKey()]);
    $payload = ($this->payload)($goal);

    expect($payload['can_update'])->toBeTrue()
        ->and($payload['can_delete'])->toBeTrue()
        ->and($payload['update_reason'])->toBeNull()
        ->and($payload['delete_reason'])->toBeNull();
});

it('carries the restriction and the period field so the editor can preselect them', function (): void {
    GateSpy::allowing('view');

    $payload = ($this->payload)(($this->goal)(GoalScopeType::User, [
        'target_user_id' => (string) $this->targetUser->getKey(),
    ]));

    expect($payload['scope_field_key'])->toBe('owner_id')
        ->and($payload['period_field_key'])->toBe('closed_on')
        ->and($payload['period_type'])->toBe('month')
        ->and($payload['direction'])->toBe(GoalDirection::AtLeast->value)
        ->and($payload['scope_type'])->toBe('user');
});

it('omits the report and the periods until they are loaded', function (): void {
    GateSpy::allowing('view');

    $payload = ($this->payload)(($this->goal)(GoalScopeType::User, [
        'target_user_id' => (string) $this->targetUser->getKey(),
    ]));

    expect($payload)->not->toHaveKey('report')
        ->and($payload)->not->toHaveKey('periods');
});

it('names the loaded report by its id, its name and its object type', function (): void {
    GateSpy::allowing('view');

    $goal = ($this->goal)(
        GoalScopeType::User,
        ['target_user_id' => (string) $this->targetUser->getKey()],
        ['report' => $this->report],
    );

    expect(($this->payload)($goal)['report'])->toBe([
        'id' => (string) $this->report->getKey(),
        'name' => 'Revenue',
        'object_type_id' => (string) $this->report->object_type_id,
    ]);
});

it('hands the loaded periods out in iso 8601 with their calculated moment', function (): void {
    GateSpy::allowing('view');

    $period = ModelStub::make(GoalPeriod::class, [
        'id' => ModelStub::ulid('period'),
        'tenant_id' => $this->tenant->getKey(),
        'goal_id' => ModelStub::ulid('goal'),
        'period_start' => '2026-04-30 22:00:00',
        'period_end' => '2026-05-31 22:00:00',
        'current_value' => '1234.5600',
        'calculated_at' => '2026-05-15 09:00:00',
    ]);

    $goal = ($this->goal)(
        GoalScopeType::User,
        ['target_user_id' => (string) $this->targetUser->getKey()],
        ['periods' => new EloquentCollection([$period])],
    );

    $payload = ($this->payload)($goal);

    expect($payload['periods'])->toHaveCount(1)
        ->and($payload['periods'][0]['period_start'])->toBe('2026-04-30T22:00:00+00:00')
        ->and($payload['periods'][0]['period_end'])->toBe('2026-05-31T22:00:00+00:00')
        ->and($payload['periods'][0]['calculated_at'])->toBe('2026-05-15T09:00:00+00:00')
        ->and($payload['periods'][0]['current_value'])->toBe('1234.5600');
});

it('leaves the calculated moment empty for a period that was never computed', function (): void {
    $period = ModelStub::make(GoalPeriod::class, [
        'id' => ModelStub::ulid('period'),
        'tenant_id' => $this->tenant->getKey(),
        'goal_id' => ModelStub::ulid('goal'),
        'period_start' => '2026-04-30 22:00:00',
        'period_end' => '2026-05-31 22:00:00',
        'current_value' => null,
        'calculated_at' => null,
    ]);

    /** @var array<string, mixed> $payload */
    $payload = (new GoalPeriodResource($period))->resolve(Request::create('/goals', 'GET'));

    expect($payload['calculated_at'])->toBeNull()
        ->and($payload['current_value'])->toBeNull();
});

it('caps the periods a goal hands out so the payload never grows without a bound', function (): void {
    expect(GoalResource::$periodLimit)->toBe(13);
});
