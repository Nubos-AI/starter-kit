<?php

declare(strict_types=1);

use App\Actions\Reminders\BulkDeleteReminderTasksAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Actions\Reminders\DeleteReminderTaskAction;
use App\Actions\Reminders\UpdateReminderTaskAction;
use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-action-tenant');
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'reminder-actor'));
    $this->recordId = ModelStub::ulid('reminder-action-record');

    $this->authority = new class extends ReminderAuthority
    {
        /**
         * @var list<mixed>
         */
        public array $asked = [];

        public bool $refuses = false;

        /**
         * @var list<string>
         */
        public array $manageable = [];

        public function __construct() {}

        public function assertMayLinkRecord(?User $user, mixed $recordId): void
        {
            $this->asked[] = $recordId;

            if ($this->refuses) {
                throw ValidationException::withMessages(['record_id' => 'refused']);
            }
        }

        public function manages(User $user, ReminderTask $reminder): bool
        {
            return in_array((string) $reminder->getKey(), $this->manageable, true);
        }
    };

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

    $this->createAction = fn (): CreateReminderTaskAction => new CreateReminderTaskAction($this->timelineWriter, $this->authority);
    $this->updateAction = fn (): UpdateReminderTaskAction => new UpdateReminderTaskAction($this->authority);

    $this->reminderWith = fn (array $attributes = []): ReminderTask => ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid('reminder-action-task'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->actor->getKey(),
        'creator_id' => $this->actor->getKey(),
        'assignee_id' => $this->actor->getKey(),
        'record_id' => null,
        'subject' => 'Call back',
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('demands a subject before it asks about the record', function (): void {
    expect(fn (): ReminderTask => ($this->createAction)()->execute(['subject' => '']))
        ->toThrow(ValidationException::class)
        ->and($this->authority->asked)->toBe([]);
});

it('keeps a linked reminder type inside the bound tenant', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): mixed => ($this->createAction)()->execute([
        'subject' => 'Call back',
        'reminder_type_id' => ModelStub::ulid('reminder-type'),
    ]));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('reminder_types'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the record a new reminder links to inside the bound tenant', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): mixed => ($this->createAction)()->execute([
        'subject' => 'Call back',
        'record_id' => $this->recordId,
    ]));

    expect($attempt?->targets('custom_records'))->toBeTrue()
        ->and($attempt?->hasBinding($this->recordId))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the record an update links to inside the tenant of the reminder', function (): void {
    $reminder = ($this->reminderWith)(['record_id' => null, 'tenant_id' => $this->tenant->getKey()]);

    $attempt = QueryShape::attemptedBy(fn (): mixed => ($this->updateAction)()->execute($reminder, [
        'record_id' => $this->recordId,
    ]));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('custom_records'))->toBeTrue()
        ->and($attempt?->hasBinding($this->recordId))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the assignee an update names inside the tenant of the reminder', function (): void {
    $reminder = ($this->reminderWith)();
    $assigneeId = ModelStub::ulid('reminder-other-assignee');

    $attempt = QueryShape::attemptedBy(fn (): mixed => ($this->updateAction)()->execute($reminder, [
        'assignee_id' => $assigneeId,
    ]));

    expect($attempt?->targets('users'))->toBeTrue()
        ->and($attempt?->hasBinding($assigneeId))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('re-checks the record right when an update unlinks the record', function (): void {
    $this->authority->refuses = true;
    $reminder = ($this->reminderWith)(['record_id' => $this->recordId]);

    QueryShape::attemptedBy(function () use ($reminder): mixed {
        try {
            return ($this->updateAction)()->execute($reminder, ['record_id' => null]);
        } catch (ValidationException) {
            return null;
        }
    });

    expect($this->authority->asked)->toBe([null]);
});

it('lets the acting user delete in a batch only the reminders that user manages', function (): void {
    $mine = ($this->reminderWith)(['id' => ModelStub::ulid('reminder-mine')]);
    $foreign = ($this->reminderWith)([
        'id' => ModelStub::ulid('reminder-foreign'),
        'owner_id' => ModelStub::ulid('reminder-other-owner'),
        'creator_id' => ModelStub::ulid('reminder-other-owner'),
        'assignee_id' => ModelStub::ulid('reminder-other-owner'),
    ]);

    $action = new class(app(DeleteReminderTaskAction::class), app(ReminderAuthority::class)) extends BulkDeleteReminderTasksAction
    {
        public function decides(User $actor, ReminderTask $reminder): bool
        {
            return $this->mayDelete($actor, $reminder);
        }
    };

    expect($action->decides($this->actor, $mine))->toBeTrue()
        ->and($action->decides($this->actor, $foreign))->toBeFalse();
});

it('refuses a batch whose identifier list is no list of identifiers', function (): void {
    $action = app(BulkDeleteReminderTasksAction::class);

    expect(fn (): Collection => $action->execute($this->actor, ['ids' => 'all']))
        ->toThrow(ValidationException::class);
});

it('reads a batch of reminders only through the tenant scoped reminder query', function (): void {
    $action = app(BulkDeleteReminderTasksAction::class);

    $attempt = QueryShape::attemptedBy(fn (): Collection => $action->execute($this->actor, ['ids' => [ModelStub::ulid('reminder-mine')]]));

    expect($attempt?->targets('reminder_tasks'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('reminder_tasks', (string) $this->tenant->getKey()))->toBeTrue();
});
