<?php

declare(strict_types=1);

use App\Console\Commands\ScanDueReminders;
use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Notifications\ReminderDueNotification;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Support\Facades\Notification;
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
