<?php

declare(strict_types=1);

use App\Actions\Tenancy\PurgeTenantAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Activities\Users\PurgeUnverifiedRegistrationsActivity;
use App\Contracts\Modules\TenantDeletionGuardInterface;
use App\DTOs\Tenancy\PurgeReport;
use App\Enums\Temporal\ScheduleEgress;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Models\MaintenanceLock;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Temporal\TemporalScheduleGateway;
use App\Support\Users\UnverifiedRegistrationDirectory;
use App\Support\Users\UserScheduleRegistrar;
use App\Workflows\Users\PurgeUnverifiedRegistrationsWorkflow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Schedule;
use Temporal\Exception\Failure\ActivityFailure;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
    config(['users.registration.unverified_ttl_days' => 7, 'users.registration.purge_batch_size' => 200]);

    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('squatter-tenant'), 'name' => 'Squatter']);

    $this->registrant = fn (array $attributes = []): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid('squatter'),
        'tenant_id' => ModelStub::ulid('squatter-tenant'),
        'email' => 'ada@acme.de',
        'status' => UserStatus::Accepted->value,
        'is_service' => false,
        'email_verified_at' => null,
        'invited_by_id' => null,
        'created_at' => '2026-11-01 08:00:00',
        ...$attributes,
    ], ['tenant' => $this->tenant]);

    $this->directory = Mockery::mock(UnverifiedRegistrationDirectory::class);
    $this->directory->shouldReceive('isSoleMemberOfTenant')->byDefault()->andReturnTrue();
    app()->instance(UnverifiedRegistrationDirectory::class, $this->directory);

    $this->purge = Mockery::mock(PurgeTenantAction::class);
    app()->instance(PurgeTenantAction::class, $this->purge);

    $this->guard = Mockery::mock(TenantDeletionGuardInterface::class);
    $this->guard->shouldReceive('assertDeletable')->byDefault();
    app()->instance(TenantDeletionGuardInterface::class, $this->guard);

    $this->locks = Mockery::mock(MaintenanceLockRegistry::class);
    $this->locks->shouldReceive('activeFor')->byDefault()->andReturnNull();
    app()->instance(MaintenanceLockRegistry::class, $this->locks);
});

afterEach(function (): void {
    Carbon::setTestNow();
    WorkflowRun::end();
    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('releases the address of an unverified self registration by purging its whole tenant and logs it', function (): void {
    Log::spy();
    $this->purge->shouldReceive('execute')->once()->with($this->tenant)->andReturn(new PurgeReport([], 0, 0, []));

    expect(app(ReleaseUnverifiedRegistrationAction::class)->execute(($this->registrant)(), RegistrationReleaseReason::ClaimedBySingleSignOn))->toBeTrue();

    Log::shouldHaveReceived('info')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
        'tenant_id' => ModelStub::ulid('squatter-tenant'),
        'user_id' => ModelStub::ulid('squatter'),
        'reason' => 'claimed_by_single_sign_on',
    ]);
});

it('never releases an account that proved its address, was invited, serves the api, is removed or is not alone in its tenant', function (array $attributes, bool $isSoleMember): void {
    $this->directory->shouldReceive('isSoleMemberOfTenant')->andReturn($isSoleMember);
    $this->purge->shouldNotReceive('execute');
    $this->guard->shouldNotReceive('assertDeletable');

    expect(app(ReleaseUnverifiedRegistrationAction::class)->execute(($this->registrant)($attributes), RegistrationReleaseReason::Expired))->toBeFalse();
})->with([
    'verified' => [['email_verified_at' => '2026-11-02 08:00:00'], true],
    'invited' => [['status' => UserStatus::Invited->value, 'invited_by_id' => ModelStub::ulid('inviter')], true],
    'accepted invitation' => [['invited_by_id' => ModelStub::ulid('inviter')], true],
    'blocked' => [['status' => UserStatus::Blocked->value], true],
    'service' => [['is_service' => true], true],
    'removed' => [['deleted_at' => '2026-11-05 08:00:00'], true],
    'tenant with further members' => [[], false],
]);

it('keeps a registration whose tenant is under maintenance or refuses its deletion and logs why', function (string $refusal): void {
    Log::spy();
    $this->purge->shouldNotReceive('execute');

    if ($refusal === 'maintenance') {
        $this->locks->shouldReceive('activeFor')->with(ModelStub::ulid('squatter-tenant'))->andReturn(new MaintenanceLock);
    }

    if ($refusal === 'guard') {
        $this->guard->shouldReceive('assertDeletable')->andThrow(new InvalidArgumentException('sandbox'));
    }

    expect(app(ReleaseUnverifiedRegistrationAction::class)->execute(($this->registrant)(), RegistrationReleaseReason::Expired))->toBeFalse();

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context['user_id'] === ModelStub::ulid('squatter') && $context['reason'] === 'expired');
})->with(['maintenance', 'guard']);

it('selects only unverified self registrations older than the cutoff that are alone in their tenant', function (): void {
    $query = QueryShape::of((new UnverifiedRegistrationDirectory)->abandonedQuery(Carbon::parse('2026-11-03 09:00:00')));

    expect($query->sql)->toBe('select "users"."id" from "users" where "users"."status" = ? and "users"."email_verified_at" is null and "users"."is_service" = ? and "users"."invited_by_id" is null and "users"."tenant_id" is not null and "users"."created_at" < ? and not exists (select * from "users" as "members" where "members"."tenant_id" = "users"."tenant_id" and "members"."id" <> "users"."id") and "users"."deleted_at" is null order by "users"."created_at" asc')
        ->and(array_map(static fn (mixed $binding): mixed => $binding instanceof CarbonInterface ? $binding->toDateTimeString() : $binding, $query->bindings))->toBe(['accepted', false, '2026-11-03 09:00:00']);
});

it('asks whether an account is the only one of its tenant, removed members included', function (): void {
    $query = QueryShape::attemptedBy(fn () => (new UnverifiedRegistrationDirectory)->isSoleMemberOfTenant(($this->registrant)()));

    expect($query?->sql)->toBe('select exists(select * from "users" where "tenant_id" = ? and "users"."id" != ?) as "exists"')
        ->and($query?->bindings)->toBe([ModelStub::ulid('squatter-tenant'), ModelStub::ulid('squatter')]);
});

it('lists the abandoned registrations with the configured age and batch size', function (): void {
    $this->directory->shouldReceive('abandonedIds')->once()
        ->withArgs(static fn (CarbonInterface $cutoff, int $limit): bool => $cutoff->toDateTimeString() === '2026-11-03 09:00:00' && $limit === 200)
        ->andReturn([ModelStub::ulid('squatter')]);

    expect(app(PurgeUnverifiedRegistrationsActivity::class)->abandonedRegistrationIds())->toBe([ModelStub::ulid('squatter')]);
});

it('releases a listed registration as expired and reports an account that is gone as not released', function (bool $isFound): void {
    StaticQueryConnection::install(fn (string $sql): array => $isFound && str_contains($sql, 'from "users"') ? [($this->registrant)()->getAttributes()] : []);
    $release = Mockery::mock(ReleaseUnverifiedRegistrationAction::class);
    $release->shouldReceive('execute')->times($isFound ? 1 : 0)
        ->withArgs(static fn (User $user, RegistrationReleaseReason $reason): bool => $user->getKey() === ModelStub::ulid('squatter') && $reason === RegistrationReleaseReason::Expired)
        ->andReturnTrue();
    app()->instance(ReleaseUnverifiedRegistrationAction::class, $release);

    expect(app(PurgeUnverifiedRegistrationsActivity::class)->releaseRegistration(ModelStub::ulid('squatter')))->toBe($isFound);
})->with(['found' => true, 'gone' => false]);

it('releases every listed registration and logs the outcome with the workflow run', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PurgeUnverifiedRegistrationsWorkflow)->run())
        ->resume(['first', 'second'])
        ->resume(true)
        ->resume(false);

    expect($run->activities())->toBe([
        ['activity' => 'UnverifiedRegistrationPurge.abandonedRegistrationIds', 'arguments' => []],
        ['activity' => 'UnverifiedRegistrationPurge.releaseRegistration', 'arguments' => ['first']],
        ['activity' => 'UnverifiedRegistrationPurge.releaseRegistration', 'arguments' => ['second']],
    ])->and($run->isFinished())->toBeTrue()
        ->and(end($run->logs))->toMatchArray(['level' => 'info', 'context' => [
            'registrations' => 2,
            'released' => 1,
            'kept' => 1,
            'failed' => 0,
            'workflow_id' => $run->workflowId,
            'run_id' => $run->runId,
        ]]);
});

it('still releases the later registrations when one fails, then logs the failure with the workflow run and fails the run', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new PurgeUnverifiedRegistrationsWorkflow)->run())
        ->resume(['first', 'second'])
        ->fail(new ActivityFailure(1, 2, 'UnverifiedRegistrationPurge.releaseRegistration', 'first', 0, 'worker'));

    expect(fn () => $run->resume(true))
        ->toThrow(ActivityFailure::class)
        ->and(array_column($run->activities(), 'arguments'))->toBe([[], ['first'], ['second']])
        ->and(end($run->logs))->toMatchArray(['level' => 'error', 'context' => [
            'registrations' => 2,
            'released' => 1,
            'kept' => 0,
            'failed' => 1,
            'workflow_id' => $run->workflowId,
            'run_id' => $run->runId,
        ]]);
});

it('registers the purge as a cron schedule of the configured time', function (): void {
    config(['users.registration.purge_cron' => '15 3 * * *', 'users.registration.purge_timezone' => 'Europe/Berlin']);
    $registered = [];
    $gateway = Mockery::mock(TemporalScheduleGateway::class);
    $gateway->shouldReceive('exists')->andReturnFalse();
    $gateway->shouldReceive('create')->once()->andReturnUsing(function (string $scheduleId, Schedule $schedule, ScheduleEgress $egress) use (&$registered): void {
        $registered[$scheduleId] = $schedule;
    });

    (new UserScheduleRegistrar($gateway))->register();

    $schedule = $registered['unverified-registration-purge'];

    expect($schedule->action)->toBeInstanceOf(StartWorkflowAction::class)
        ->and($schedule->action->workflowType->name)->toBe('PurgeUnverifiedRegistrationsWorkflow')
        ->and($schedule->spec->cronStringList)->toBe(['15 3 * * *'])
        ->and($schedule->spec->timezoneName)->toBe('Europe/Berlin');
});
