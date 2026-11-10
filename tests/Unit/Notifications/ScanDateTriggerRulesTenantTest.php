<?php

declare(strict_types=1);

use App\Actions\Reminders\CreateReminderTaskAction;
use App\Console\Commands\ScanApprovalDeadlines;
use App\Console\Commands\ScanDateTriggerRules;
use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\RuleNotification;
use App\Support\Approvals\ApprovalDeadlineRunner;
use App\Support\Engine\DateOffset;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Notifications\RuleMatcher;
use App\Support\Sql\LiteralIdentifier;
use App\Support\Tenancy\TenantBinder;
use App\Support\Watchers\WatcherResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('date-scan-tenant');
    $this->matcher = Mockery::mock(RuleMatcher::class);
    $this->runner = Mockery::mock(RuleActionRunner::class);
    $this->watcherResolver = Mockery::mock(WatcherResolver::class);

    $locks = Mockery::mock(MaintenanceLockRegistry::class);
    $locks->shouldReceive('lockedTenantIds')->andReturn([]);
    $this->locks = $locks;

    $this->handle = fn (): int => (new ScanDateTriggerRules)->handle(
        $this->matcher,
        $this->runner,
        $this->watcherResolver,
        $this->locks,
        new TenantBinder,
        new LiteralIdentifier,
        new DateOffset,
    );

    $this->ruleRow = fn (string $seed, string $tenantId): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $tenantId,
        'trigger_type' => RuleTriggerType::DateBased->value,
        'is_active' => true,
        'config' => json_encode(['date_field_key' => 'due_on', 'lead_stages' => [['value' => 1, 'unit' => 'day']]]),
    ];

    $this->rulesOf = fn (bool $isTenantKnown, array $records = []): StaticQueryConnection => StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "notification_rules"') => [($this->ruleRow)('date-scan-rule', $this->tenantId)],
        str_contains($sql, 'from "tenants"') => $isTenantKnown ? [['id' => $this->tenantId, 'name' => 'Nubos']] : [],
        str_contains($sql, 'from "custom_records"') => $records,
        default => [],
    });
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
    CarbonImmutable::setTestNow();
});

it('skips the rule of a tenant that no longer exists instead of evaluating it without a tenant', function (): void {
    ($this->rulesOf)(false);
    $this->matcher->shouldNotReceive('scopeQuery');

    expect(($this->handle)())->toBe(ScanDateTriggerRules::SUCCESS);
});

it('evaluates the rule of a known tenant inside that tenant and releases it afterwards', function (): void {
    ($this->rulesOf)(true);
    Log::spy();
    $seen = [];
    $this->matcher->shouldReceive('scopeQuery')->once()->andReturnUsing(function () use (&$seen): never {
        $seen = [app('current_tenant'), Context::getHidden('tenant_id')];

        throw new RuntimeException('stop after the tenant check');
    });

    expect(($this->handle)())->toBe(ScanDateTriggerRules::FAILURE);

    expect($seen[0])->toBeInstanceOf(Tenant::class)
        ->and($seen[1])->toBe($this->tenantId)
        ->and(app()->bound('current_tenant'))->toBeFalse()
        ->and(Context::hasHidden('tenant_id'))->toBeFalse();
});

it('logs a rule that fails with its identifiers and still evaluates the rules of the other tenants after it', function (): void {
    $otherTenantId = ModelStub::ulid('date-scan-other-tenant');
    StaticQueryConnection::install(fn (string $sql, array $bindings): array => match (true) {
        str_contains($sql, 'from "notification_rules"') => [
            ($this->ruleRow)('date-scan-broken-rule', $this->tenantId),
            ($this->ruleRow)('date-scan-healthy-rule', $otherTenantId),
        ],
        str_contains($sql, 'from "tenants"') => [['id' => $bindings[0], 'name' => 'Nubos']],
        default => [],
    });
    Log::spy();
    $evaluated = [];
    $this->matcher->shouldReceive('scopeQuery')->twice()->andReturnUsing(function (NotificationRule $rule) use (&$evaluated): mixed {
        $evaluated[] = $rule->getKey();

        if ($rule->getKey() === ModelStub::ulid('date-scan-broken-rule')) {
            throw new RuntimeException('broken filter definition');
        }

        return CustomRecord::query();
    });

    expect(($this->handle)())->toBe(ScanDateTriggerRules::FAILURE)
        ->and($evaluated)->toBe([ModelStub::ulid('date-scan-broken-rule'), ModelStub::ulid('date-scan-healthy-rule')]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === ModelStub::ulid('date-scan-broken-rule')
        && $context['tenant_id'] === $this->tenantId
        && $context['exception'] instanceof RuntimeException);
});

it('logs a record whose date cannot be read and still notifies the watchers of the records after it', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 10:00:00');
    $healthy = ModelStub::ulid('date-scan-healthy-record');
    ($this->rulesOf)(true, [
        ['id' => ModelStub::ulid('date-scan-broken-record'), 'tenant_id' => $this->tenantId, 'data' => json_encode(['due_on' => 'not a date at all'])],
        ['id' => $healthy, 'tenant_id' => $this->tenantId, 'data' => json_encode(['due_on' => '2026-11-10'])],
    ]);
    Log::spy();
    $recipient = ModelStub::make(User::class, ['id' => ModelStub::ulid('date-scan-recipient'), 'tenant_id' => $this->tenantId, 'timezone' => 'UTC']);
    $this->matcher->shouldReceive('scopeQuery')->once()->andReturn(CustomRecord::query());
    $this->watcherResolver->shouldReceive('recipientsFor')->andReturn(new Collection([$recipient]));
    $this->runner->shouldReceive('dispatchToEach')->once()->withArgs(static fn (NotificationRule $rule, CustomRecord $record, Collection $recipients): bool => $record->getKey() === $healthy
        && $recipients->values()->all() === [$recipient]);

    expect(($this->handle)())->toBe(ScanDateTriggerRules::FAILURE);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === ModelStub::ulid('date-scan-rule')
        && $context['record_id'] === ModelStub::ulid('date-scan-broken-record')
        && $context['tenant_id'] === $this->tenantId
        && $context['stage'] === '1:day');
});

it('still tells the other watchers of a record when one of them cannot be reached and reports the failure', function (): void {
    CarbonImmutable::setTestNow('2026-11-09 10:00:00');
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "notification_rules"') => [($this->ruleRow)('date-scan-rule', $this->tenantId)],
        str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Nubos']],
        str_contains($sql, 'from "custom_records"') => [['id' => ModelStub::ulid('date-scan-shared-record'), 'tenant_id' => $this->tenantId, 'data' => json_encode(['due_on' => '2026-11-10'])]],
        default => [],
    }, static fn (): int => 1);
    Log::spy();
    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class];
        }
    });
    $broken = ModelStub::make(User::class, ['id' => ModelStub::ulid('date-scan-broken-watcher'), 'tenant_id' => $this->tenantId, 'timezone' => 'UTC']);
    $healthy = ModelStub::make(User::class, ['id' => ModelStub::ulid('date-scan-healthy-watcher'), 'tenant_id' => $this->tenantId, 'timezone' => 'UTC']);
    $notifications = new class((string) $broken->getKey()) extends NotificationFake
    {
        public function __construct(private readonly string $brokenUserId) {}

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            if ($notifiables instanceof User && $notifiables->getKey() === $this->brokenUserId) {
                throw new RuntimeException('push endpoint gone');
            }

            parent::sendNow($notifiables, $notification, $channels);
        }
    };
    Notification::swap($notifications);
    $this->matcher->shouldReceive('scopeQuery')->once()->andReturn(CustomRecord::query());
    $this->watcherResolver->shouldReceive('recipientsFor')->andReturn(new Collection([$broken, $healthy]));

    $exitCode = (new ScanDateTriggerRules)->handle(
        $this->matcher,
        new RuleActionRunner($this->watcherResolver, Mockery::mock(CreateReminderTaskAction::class)),
        $this->watcherResolver,
        $this->locks,
        new TenantBinder,
        new LiteralIdentifier,
        new DateOffset,
    );

    expect($exitCode)->toBe(ScanDateTriggerRules::FAILURE);
    $notifications->assertSentToTimes($healthy, RuleNotification::class, 1);
});

it('resolves the services of a periodic scan per run so a long-lived worker never keeps a stale registry', function (string $command, string $service): void {
    $constructor = (new ReflectionClass($command))->getConstructor();
    $handleParameters = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        (new ReflectionMethod($command, 'handle'))->getParameters(),
    );

    expect($constructor === null || $constructor->getDeclaringClass()->getName() !== $command)->toBeTrue()
        ->and($handleParameters)->toContain($service);
})->with([
    'date-based notification rules' => [ScanDateTriggerRules::class, RuleMatcher::class],
    'approval deadlines' => [ScanApprovalDeadlines::class, ApprovalDeadlineRunner::class],
]);
