<?php

declare(strict_types=1);

use App\Console\Commands\ScanDueReminders;
use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\ReminderDueNotification;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-scan-tenant');

    $this->timelineWriter = new class extends ReminderTimelineWriter
    {
        /**
         * @var list<ReminderEventState>
         */
        public array $recorded = [];

        public function __construct() {}

        public function record(ReminderTask $reminder, ReminderEventState $state, DateTimeInterface $occurredAt, ?string $actorId = null, ?ActorType $actorType = null): void
        {
            $this->recorded[] = $state;
        }
    };

    $this->lockRegistry = new class extends MaintenanceLockRegistry
    {
        public function __construct() {}

        public function lockedTenantIds(): array
        {
            return [];
        }
    };

    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class];
        }
    });

    $this->command = fn (): ScanDueReminders => new ScanDueReminders;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('enumerates the due reminders across every tenant, not only the bound one', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): int => ($this->command)()->handle($this->timelineWriter, $this->lockRegistry));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('reminder_tasks'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('reminder_tasks', (string) $this->tenant->getKey()))->toBeFalse();
});

it('picks up only reminders that are open, un-notified and already due', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): int => ($this->command)()->handle($this->timelineWriter, $this->lockRegistry));

    expect($attempt?->sql)->toContain('"done_at" is null')
        ->toContain('"notified_at" is null')
        ->toContain('"due_at" is not null')
        ->toContain('"due_at" <= ?');
});

it('leaves the reminder of a tenant under maintenance alone and notifies the free one exactly once', function (int $claimedRows, bool $isNotified): void {
    Notification::fake();
    $locked = ModelStub::ulid('locked-scan-tenant');
    $free = ModelStub::ulid('free-scan-tenant');
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "reminder_tasks"') => [
                ['id' => ModelStub::ulid('locked-reminder'), 'tenant_id' => $locked, 'assignee_id' => ModelStub::ulid('locked-assignee'), 'title' => 'A', 'due_at' => '2026-01-01 08:00:00'],
                ['id' => ModelStub::ulid('free-reminder'), 'tenant_id' => $free, 'assignee_id' => ModelStub::ulid('free-assignee'), 'title' => 'B', 'due_at' => '2026-01-01 08:00:00'],
            ],
            str_contains($sql, 'from "tenants"') => [['id' => $free, 'name' => 'Free', 'slug' => 'free']],
            str_contains($sql, 'from "users"') => [
                ['id' => ModelStub::ulid('locked-assignee'), 'tenant_id' => $locked, 'email' => 'a@acme.test'],
                ['id' => ModelStub::ulid('free-assignee'), 'tenant_id' => $free, 'email' => 'b@acme.test'],
            ],
            default => [],
        },
        static fn (): int => $claimedRows,
    );
    $registry = new class($locked) extends MaintenanceLockRegistry
    {
        public function __construct(private readonly string $locked) {}

        public function lockedTenantIds(): array
        {
            return [$this->locked];
        }
    };

    try {
        ($this->command)()->handle($this->timelineWriter, $registry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $claims = $connection->writtenSqlOf('reminder_tasks');

    expect($claims)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['bindings'])->toContain(ModelStub::ulid('free-reminder'))
        ->and($connection->writtenStatements[0]['bindings'])->not->toContain(ModelStub::ulid('locked-reminder'))
        ->and($this->timelineWriter->recorded)->toBe($isNotified ? [ReminderEventState::Due] : []);

    Notification::assertSentTimes(ReminderDueNotification::class, $isNotified ? 1 : 0);
})->with([
    'the claim succeeds' => [1, true],
    'another scan claimed it first' => [0, false],
]);

it('logs a reminder whose notification fails with its identifiers and still notifies the reminders after it', function (): void {
    $tenantId = ModelStub::ulid('isolated-scan-tenant');
    $failingAssignee = ModelStub::ulid('failing-assignee');
    $healthyAssignee = ModelStub::ulid('healthy-assignee');
    $notifications = new class($failingAssignee) extends NotificationFake
    {
        public function __construct(private readonly string $failingAssignee) {}

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            if ($notifiables instanceof User && $notifiables->getKey() === $this->failingAssignee) {
                throw new RuntimeException('mail transport down');
            }

            parent::sendNow($notifiables, $notification, $channels);
        }
    };
    Notification::swap($notifications);
    Log::spy();
    $users = [
        ['id' => $failingAssignee, 'tenant_id' => $tenantId, 'email' => 'a@acme.test'],
        ['id' => $healthyAssignee, 'tenant_id' => $tenantId, 'email' => 'b@acme.test'],
    ];
    $connection = StaticQueryConnection::install(
        static fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "reminder_tasks"') => [
                ['id' => ModelStub::ulid('failing-reminder'), 'tenant_id' => $tenantId, 'assignee_id' => $failingAssignee, 'title' => 'A', 'due_at' => '2026-01-01 08:00:00'],
                ['id' => ModelStub::ulid('healthy-reminder'), 'tenant_id' => $tenantId, 'assignee_id' => $healthyAssignee, 'title' => 'B', 'due_at' => '2026-01-01 08:00:00'],
            ],
            str_contains($sql, 'from "tenants"') => [['id' => $tenantId, 'name' => 'Isolated', 'slug' => 'isolated']],
            str_contains($sql, 'from "users"') => array_values(array_filter($users, static fn (array $user): bool => in_array($user['id'], $bindings, true))),
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        $exitCode = ($this->command)()->handle($this->timelineWriter, $this->lockRegistry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($connection->writtenSqlOf('reminder_tasks'))->toHaveCount(3)
        ->and($this->timelineWriter->recorded)->toBe([ReminderEventState::Due])
        ->and($exitCode)->toBe(ScanDueReminders::FAILURE);

    $notifications->assertSentTimes(ReminderDueNotification::class, 1);
    Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context['reminder_id'] === ModelStub::ulid('failing-reminder')
        && $context['tenant_id'] === $tenantId
        && $context['assignee_id'] === $failingAssignee
        && $context['exception'] instanceof RuntimeException);
});

it('releases the claim of a reminder whose notification failed so the next scan delivers it instead of treating it as notified', function (): void {
    $tenantId = ModelStub::ulid('release-scan-tenant');
    $assignee = ModelStub::ulid('release-assignee');
    $reminderId = ModelStub::ulid('release-reminder');
    $notifications = new class extends NotificationFake
    {
        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('mail transport down');
        }
    };
    Notification::swap($notifications);
    Log::spy();
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "reminder_tasks"') => [
                ['id' => $reminderId, 'tenant_id' => $tenantId, 'assignee_id' => $assignee, 'title' => 'A', 'due_at' => '2026-01-01 08:00:00'],
            ],
            str_contains($sql, 'from "tenants"') => [['id' => $tenantId, 'name' => 'Release', 'slug' => 'release']],
            str_contains($sql, 'from "users"') => [['id' => $assignee, 'tenant_id' => $tenantId, 'email' => 'a@acme.test']],
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        ($this->command)()->handle($this->timelineWriter, $this->lockRegistry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $writes = array_values(array_filter($connection->writtenStatements, static fn (array $write): bool => str_contains($write['sql'], '"reminder_tasks"')));
    $claimedAt = $writes[0]['bindings'][0];

    expect($writes)->toHaveCount(2)
        ->and($writes[1]['sql'])->toBe('update "reminder_tasks" set "notified_at" = ?, "updated_at" = ? where "reminder_tasks"."id" = ? and "notified_at" = ?')
        ->and($writes[1]['bindings'][0])->toBeNull()
        ->and(array_slice($writes[1]['bindings'], 2))->toBe([$reminderId, $claimedAt])
        ->and($this->timelineWriter->recorded)->toBe([]);
});

it('keeps the claim when the notification went out and only the timeline entry failed, so the assignee is not notified twice', function (): void {
    $tenantId = ModelStub::ulid('timeline-scan-tenant');
    $assignee = ModelStub::ulid('timeline-assignee');
    Notification::fake();
    Log::spy();
    $failingWriter = new class extends ReminderTimelineWriter
    {
        public function __construct() {}

        public function record(ReminderTask $reminder, ReminderEventState $state, DateTimeInterface $occurredAt, ?string $actorId = null, ?ActorType $actorType = null): void
        {
            throw new RuntimeException('timeline down');
        }
    };
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "reminder_tasks"') => [
                ['id' => ModelStub::ulid('timeline-reminder'), 'tenant_id' => $tenantId, 'assignee_id' => $assignee, 'title' => 'A', 'due_at' => '2026-01-01 08:00:00'],
            ],
            str_contains($sql, 'from "tenants"') => [['id' => $tenantId, 'name' => 'Timeline', 'slug' => 'timeline']],
            str_contains($sql, 'from "users"') => [['id' => $assignee, 'tenant_id' => $tenantId, 'email' => 'a@acme.test']],
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        $exitCode = ($this->command)()->handle($failingWriter, $this->lockRegistry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($connection->writtenSqlOf('reminder_tasks'))->toHaveCount(1)
        ->and($exitCode)->toBe(ScanDueReminders::FAILURE);

    Notification::assertSentTimes(ReminderDueNotification::class, 1);
    Log::shouldHaveReceived('error')->once();
});

it('delivers every channel of a due reminder once, so a mail failure after the inbox entry retries only the mail', function (?string $deliveredBefore, ?string $failingChannel, array $expectedSends, array $expectedChannelWrites): void {
    $tenantId = ModelStub::ulid('channel-scan-tenant');
    $assignee = ModelStub::ulid('channel-assignee');
    $reminderId = ModelStub::ulid('channel-reminder');
    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class, 'mail'];
        }
    });
    $notifications = new class($failingChannel) extends NotificationFake
    {
        /**
         * @var list<list<string>>
         */
        public array $sentChannels = [];

        public function __construct(private readonly ?string $failingChannel) {}

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            if ($channels === [$this->failingChannel]) {
                throw new RuntimeException('mail transport down');
            }

            $this->sentChannels[] = $channels ?? [];

            parent::sendNow($notifiables, $notification, $channels);
        }
    };
    Notification::swap($notifications);
    Log::spy();
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "reminder_tasks"') => [
                ['id' => $reminderId, 'tenant_id' => $tenantId, 'assignee_id' => $assignee, 'subject' => 'A', 'due_at' => '2026-01-01 08:00:00', 'notified_channels' => $deliveredBefore],
            ],
            str_contains($sql, 'from "tenants"') => [['id' => $tenantId, 'name' => 'Channel', 'slug' => 'channel']],
            str_contains($sql, 'from "users"') => [['id' => $assignee, 'tenant_id' => $tenantId, 'email' => 'a@acme.test']],
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        ($this->command)()->handle($this->timelineWriter, $this->lockRegistry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $channelWrites = array_values(array_map(
        static fn (array $write): mixed => $write['bindings'][0],
        array_filter($connection->writtenStatements, static fn (array $write): bool => str_contains($write['sql'], 'set "notified_channels" = ?')),
    ));

    expect($notifications->sentChannels)->toBe($expectedSends)
        ->and($channelWrites)->toBe($expectedChannelWrites);
})->with([
    'the mail fails after the inbox entry' => [null, 'mail', [[InboxChannel::class]], [json_encode([InboxChannel::class])]],
    'the retry after the inbox entry was delivered' => [json_encode([InboxChannel::class]), null, [['mail']], [null]],
    'a first scan that delivers every channel' => [null, null, [[InboxChannel::class], ['mail']], [json_encode([InboxChannel::class]), null]],
]);
