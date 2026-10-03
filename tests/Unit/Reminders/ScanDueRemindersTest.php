<?php

declare(strict_types=1);

use App\Console\Commands\ScanDueReminders;
use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Timeline\ReminderTimelineWriter;
use Tests\Support\AccessContext;
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

it('asks the maintenance lock registry which tenants to skip before it reads a reminder', function (): void {
    $asked = false;

    $registry = new class($asked) extends MaintenanceLockRegistry
    {
        public function __construct(private bool &$asked) {}

        public function lockedTenantIds(): array
        {
            $this->asked = true;

            return [];
        }
    };

    QueryShape::attemptedBy(fn (): int => ($this->command)()->handle($this->timelineWriter, $registry));

    expect($asked)->toBeTrue();
});
