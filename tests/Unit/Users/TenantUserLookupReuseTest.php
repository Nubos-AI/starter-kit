<?php

declare(strict_types=1);

use App\Actions\Dashboards\ShareDashboardAction;
use App\Actions\Governance\CreateAbsenceDelegationAction;
use App\Actions\Segments\ShareSegmentAction;
use App\Enums\Watchers\WatcherSource;
use App\Http\Controllers\Engine\RecordActivitiesController;
use App\Http\Controllers\Settings\AbsencesController;
use App\Http\Controllers\Skills\SkillsController;
use App\Http\Controllers\Teams\TeamsController;
use App\Http\Controllers\Watchers\WatchersController;
use App\Models\CustomRecord;
use App\Models\Dashboard;
use App\Models\ObjectType;
use App\Models\RecordWatcher;
use App\Models\Segment;
use App\Models\Skill;
use App\Models\User;
use App\Support\Governance\AbsenceDelegationAccessGuard;
use App\Support\Links\SourceLinkCatalog;
use App\Support\Teams\TeamSegment;
use App\Support\Tenancy\TenantUserIdResolver;
use App\Support\Users\TenantUserOptions;
use App\Support\Users\TenantUserResolver;
use App\Support\Watchers\WatcherAutoSubscriber;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('lookup-tenant');
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, ['first_name' => 'Ada', 'last_name' => 'Admin', 'is_service' => false], 'lookup-actor'));
    $this->member = AccessContext::user($this->tenant, ['first_name' => 'Ben', 'last_name' => 'Berger', 'email' => 'ben@example.test', 'is_service' => false], 'lookup-member');
    $this->option = ['value' => (string) $this->member->getKey(), 'label' => 'Ben Berger', 'description' => 'ben@example.test', 'avatar' => ['name' => 'Ben Berger']];

    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => match (true) {
        str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => 0]],
        default => [],
    }, static fn (): int => 1);

    $this->userOptions = Mockery::mock(TenantUserOptions::class);
    app()->instance(TenantUserOptions::class, $this->userOptions);
    $this->tenantUsers = Mockery::mock(TenantUserResolver::class);
    app()->instance(TenantUserResolver::class, $this->tenantUsers);
    $this->tenantUserIds = Mockery::mock(TenantUserIdResolver::class);
    app()->instance(TenantUserIdResolver::class, $this->tenantUserIds);

    $this->request = function (array $input = [], string $method = 'GET'): Request {
        $request = Request::create('/probe', $method, $input);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->actor);
        app()->instance('request', $request);

        return $request;
    };

    $this->propsOf = static fn (mixed $response, Request $request): array => $response->toResponse($request)->original->getData()['page']['props'];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('offers the owners and members of a team from the user options of the tenant', function (): void {
    GateSpy::allowing('create');
    $this->userOptions->shouldReceive('allForUser')->once()->with($this->actor)->andReturn([$this->option]);
    $request = ($this->request)();

    $props = ($this->propsOf)(app(TeamsController::class)->create($request), $request);

    expect($props['ownerOptions'])->toBe([$this->option])
        ->and($this->connection->sqlOf('users'))->toBe([]);
});

it('offers the persons of a skill from the user options of the tenant', function (): void {
    $this->userOptions->shouldReceive('allForUser')->once()->with($this->actor)->andReturn([$this->option]);
    $skill = ModelStub::make(Skill::class, ['id' => ModelStub::ulid('lookup-skill'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Steuern']);
    $request = ($this->request)();

    $props = ($this->propsOf)(app(SkillsController::class)->edit($request, $skill), $request);

    expect($props['userOptions'])->toBe([$this->option])
        ->and(array_filter($this->connection->sqlOf('users'), static fn (string $sql): bool => !str_contains($sql, '"skill_user"')))->toBe([]);
});

it('opens the person of an absence and lists the candidates through the tenant user lookups', function (): void {
    GateSpy::allowing('viewAny', 'update', 'delete');
    AccessContext::grant('absences.manage', 'members.view');
    $accessGuard = Mockery::mock(AbsenceDelegationAccessGuard::class);
    $accessGuard->shouldReceive('mayManageAbsencesOf')->andReturnTrue();
    app()->instance(AbsenceDelegationAccessGuard::class, $accessGuard);
    $this->tenantUsers->shouldReceive('resolve')->once()->with($this->actor, (string) $this->member->getKey())->andReturn($this->member);
    $this->userOptions->shouldReceive('candidates')->once()->with((string) $this->tenant->getKey(), '', false)->andReturn(collect([$this->actor, $this->member]));
    $request = ($this->request)(['user' => (string) $this->member->getKey()]);

    $props = ($this->propsOf)(app(AbsencesController::class)->index($request), $request);

    expect($props['subject']['id'])->toBe((string) $this->member->getKey())
        ->and(array_column($props['delegateOptions'], 'value'))->toBe([(string) $this->actor->getKey()])
        ->and($this->connection->sqlOf('users'))->toBe([]);
});

it('refuses a service account as the person of an absence', function (): void {
    GateSpy::allowing('viewAny');
    AccessContext::grant('absences.manage');
    $service = AccessContext::user($this->tenant, ['is_service' => true], 'lookup-service');
    $this->tenantUsers->shouldReceive('resolve')->once()->andReturn($service);
    $request = ($this->request)(['user' => (string) $service->getKey()]);

    expect(fn () => app(AbsencesController::class)->index($request))->toThrow(NotFoundHttpException::class);
});

it('lists the assignees of record activities from the user options of the tenant', function (): void {
    GateSpy::allowing('view', 'update');
    $catalog = Mockery::mock(SourceLinkCatalog::class);
    $catalog->shouldReceive('linksFor')->andReturn([]);
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('lookup-record'), 'tenant_id' => $this->tenant->getKey()]);
    $this->userOptions->shouldReceive('candidates')->once()->with((string) $this->tenant->getKey(), '', false)->andReturn(collect([$this->member]));
    $request = ($this->request)();

    $payload = app(RecordActivitiesController::class)->index($request, $record, $catalog)->toResponse($request)->getData(true);

    expect($payload['assignees'])->toBe([['id' => (string) $this->member->getKey(), 'name' => 'Ben Berger']])
        ->and($this->connection->sqlOf('users'))->toBe([]);
});

it('adds a watcher only after the tenant user resolver found the person', function (): void {
    GateSpy::allowing('view');
    AccessContext::grant('companies.watchers.manage');
    $objectType = ModelStub::make(ObjectType::class, ['id' => ModelStub::ulid('lookup-type'), 'tenant_id' => $this->tenant->getKey(), 'slug' => 'companies']);
    $recordRow = ['id' => ModelStub::ulid('lookup-record'), 'tenant_id' => $this->tenant->getKey(), 'object_type_id' => $objectType->getKey(), 'data' => '{}', 'deleted_at' => null];
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => match (true) {
        str_contains($sql, 'from "custom_records"') => [$recordRow],
        str_contains($sql, 'from "object_types"') => [$objectType->getAttributes()],
        default => [],
    }, static fn (): int => 1);
    $eligibility = Mockery::mock(WatcherEligibility::class);
    $eligibility->shouldReceive('assertMayWatch')->once();
    app()->instance(WatcherEligibility::class, $eligibility);
    $subscriber = Mockery::mock(WatcherAutoSubscriber::class);
    $subscriber->shouldReceive('subscribe')->once()->with($recordRow['id'], (string) $this->member->getKey(), WatcherSource::Manual, (string) $this->actor->getKey())
        ->andReturn(ModelStub::make(RecordWatcher::class, ['id' => ModelStub::ulid('lookup-watcher'), 'record_id' => $recordRow['id'], 'user_id' => (string) $this->member->getKey(), 'source' => WatcherSource::Manual->value], ['user' => $this->member]));
    app()->instance(WatcherAutoSubscriber::class, $subscriber);
    $this->tenantUsers->shouldReceive('resolve')->once()->with($this->actor, (string) $this->member->getKey())->andReturn($this->member);

    app(WatchersController::class)->addWatcher(($this->request)([], 'POST'), $recordRow['id'], (string) $this->member->getKey());

    expect($this->connection->sqlOf('users'))->toBe([]);
});

it('refuses a watcher the tenant user resolver does not find', function (): void {
    GateSpy::allowing('view');
    AccessContext::grant('companies.watchers.manage');
    $objectType = ModelStub::make(ObjectType::class, ['id' => ModelStub::ulid('lookup-type'), 'tenant_id' => $this->tenant->getKey(), 'slug' => 'companies']);
    $recordRow = ['id' => ModelStub::ulid('lookup-record'), 'tenant_id' => $this->tenant->getKey(), 'object_type_id' => $objectType->getKey(), 'data' => '{}', 'deleted_at' => null];
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => match (true) {
        str_contains($sql, 'from "custom_records"') => [$recordRow],
        str_contains($sql, 'from "object_types"') => [$objectType->getAttributes()],
        default => [],
    }, static fn (): int => 1);
    $subscriber = Mockery::mock(WatcherAutoSubscriber::class);
    $subscriber->shouldNotReceive('subscribe');
    app()->instance(WatcherAutoSubscriber::class, $subscriber);
    $this->tenantUsers->shouldReceive('resolve')->once()->andThrow(new ModelNotFoundException);

    expect(fn () => app(WatchersController::class)->addWatcher(($this->request)([], 'POST'), $recordRow['id'], ModelStub::ulid('stranger')))->toThrow(ModelNotFoundException::class);
});

it('checks the person and the delegate of an absence against the tenant user lookups', function (): void {
    AccessContext::grant('absences.manage');
    $accessGuard = Mockery::mock(AbsenceDelegationAccessGuard::class);
    $accessGuard->shouldReceive('assertMayDelegateFor')->once();
    app()->instance(AbsenceDelegationAccessGuard::class, $accessGuard);
    $delegateId = ModelStub::ulid('lookup-delegate');
    $this->tenantUserIds->shouldReceive('resolve')->with((string) $this->tenant->getKey(), [(string) $this->member->getKey()])->andReturn([(string) $this->member->getKey()]);
    $this->tenantUserIds->shouldReceive('resolve')->with((string) $this->tenant->getKey(), [$delegateId])->andReturn([$delegateId]);
    $this->tenantUsers->shouldReceive('resolve')->once()->with($this->actor, (string) $this->member->getKey())->andReturn($this->member);

    app(CreateAbsenceDelegationAction::class)->execute($this->actor, ['userId' => (string) $this->member->getKey(), 'delegateId' => $delegateId, 'startsAt' => '2026-04-01T00:00:00+00:00', 'endsAt' => '2026-04-08T00:00:00+00:00']);

    expect($this->connection->sqlOf('users'))->toBe([]);
});

it('refuses a delegate the tenant user lookup does not know', function (): void {
    $this->tenantUserIds->shouldReceive('resolve')->andReturnUsing(static fn (string $tenantId, array $ids): array => $ids === [ModelStub::ulid('stranger')] ? [] : $ids);

    expect(fn () => app(CreateAbsenceDelegationAction::class)->execute($this->actor, ['userId' => (string) $this->actor->getKey(), 'delegateId' => ModelStub::ulid('stranger'), 'startsAt' => '2026-04-01T00:00:00+00:00', 'endsAt' => '2026-04-08T00:00:00+00:00']))
        ->toThrow(ValidationException::class);
});

it('shares a dashboard or a view with a person only once the tenant user lookup knows the person', function (string $action, string $model): void {
    GateSpy::allowing('share');
    $subject = ModelStub::make($model, ['id' => ModelStub::ulid('lookup-shared'), 'tenant_id' => $this->tenant->getKey(), 'owner_id' => (string) $this->actor->getKey()]);
    $this->tenantUserIds->shouldReceive('resolve')->once()->with((string) $this->tenant->getKey(), [ModelStub::ulid('stranger')])->andReturn([]);

    expect(fn () => app($action)->execute($subject, ['grantee_type' => 'user', 'grantee_id' => ModelStub::ulid('stranger')], $this->actor))->toThrow(ModelNotFoundException::class)
        ->and($this->connection->sqlOf('users'))->toBe([]);
})->with([
    'dashboard' => [ShareDashboardAction::class, Dashboard::class],
    'view' => [ShareSegmentAction::class, Segment::class],
]);
