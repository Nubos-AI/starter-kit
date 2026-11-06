<?php

declare(strict_types=1);

use App\Actions\Reminders\BulkDeleteReminderTasksAction;
use App\Actions\Reminders\CompleteReminderTaskAction;
use App\Actions\Reminders\CopyReminderOccurrenceAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Actions\Reminders\DeleteReminderTaskAction;
use App\Actions\Reminders\UpdateReminderTaskAction;
use App\Enums\Audit\ActorType;
use App\Enums\Timeline\ReminderEventState;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Links\SourceLinkValidator;
use App\Support\Recurrence\RecurrenceExpander;
use App\Support\Recurrence\RecurrenceRuleValidator;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Timeline\ReminderTimelineWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticSourceLinkResolver;
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

    $this->mailId = ModelStub::ulid('reminder-action-mail');
    $this->foreignMailId = ModelStub::ulid('reminder-action-foreign-mail');
    $this->mails = new StaticSourceLinkResolver('email_message', [], [$this->mailId]);
    config(['links.sources.email_message' => StaticSourceLinkResolver::class]);
    app()->instance(StaticSourceLinkResolver::class, $this->mails);

    $this->createAction = fn (): CreateReminderTaskAction => new CreateReminderTaskAction($this->timelineWriter, $this->authority, app(SourceLinkValidator::class), app(RecurrenceRuleValidator::class));
    $this->copyAction = fn (): CopyReminderOccurrenceAction => new CopyReminderOccurrenceAction($this->timelineWriter);
    $this->completeAction = fn (): CompleteReminderTaskAction => new CompleteReminderTaskAction($this->timelineWriter, app(RecurrenceExpander::class), ($this->copyAction)());
    $this->updateAction = fn (): UpdateReminderTaskAction => new UpdateReminderTaskAction($this->authority, app(SourceLinkValidator::class), app(RecurrenceRuleValidator::class), app(RecurrenceExpander::class), ($this->copyAction)());
    $this->deleteAction = fn (): DeleteReminderTaskAction => new DeleteReminderTaskAction(app(RecurrenceRuleValidator::class), app(RecurrenceExpander::class));
    $this->running = function (ReminderTask $reminder, Closure $call, array $siblings = []): StaticQueryConnection {
        $connection = StaticQueryConnection::install(
            fn (string $sql): array => match (true) {
                str_contains($sql, '"series_id" = ?') => $siblings,
                str_contains($sql, 'from "reminder_tasks"') => [$reminder->getAttributes()],
                default => [],
            },
            fn (): int => 1,
        );

        try {
            $call();
        } finally {
            StaticQueryConnection::uninstall();
        }

        return $connection;
    };

    $this->rowOf = static function (?array $insert): array {
        expect($insert)->not->toBeNull();

        preg_match('/\(([^)]*)\) values/', $insert['sql'], $columns);

        return array_combine(
            array_map(fn (string $column): string => trim($column, ' "'), explode(',', $columns[1])),
            $insert['bindings'],
        );
    };

    $this->insertedReminder = function (array $input): array {
        FakePresenceVerifier::holding(['custom_records' => [$this->recordId]]);
        $connection = StaticQueryConnection::install(fn (): array => [], fn (): int => 1);

        try {
            ($this->createAction)()->execute($input);
        } finally {
            StaticQueryConnection::uninstall();
        }

        return ($this->rowOf)(collect($connection->writtenStatements)
            ->first(fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "reminder_tasks"')));
    };

    $this->completed = fn (ReminderTask $reminder): StaticQueryConnection => ($this->running)($reminder, fn () => ($this->completeAction)()->execute($reminder));

    $this->writesOf = static fn (StaticQueryConnection $connection, string $prefix): array => array_values(array_filter(
        $connection->writtenStatements,
        static fn (array $statement): bool => str_starts_with($statement['sql'], $prefix),
    ));

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

    $deleted = new ArrayObject;
    $deleteReminder = Mockery::mock(DeleteReminderTaskAction::class);
    $deleteReminder->shouldReceive('execute')->andReturnUsing(static function (ReminderTask $reminder) use ($deleted): void {
        $deleted[] = (string) $reminder->getKey();
    });

    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "reminder_tasks"')
        ? [$mine->getAttributes(), $foreign->getAttributes()]
        : []);

    try {
        (new BulkDeleteReminderTasksAction($deleteReminder, app(ReminderAuthority::class)))
            ->execute($this->actor, ['ids' => [(string) $mine->getKey(), (string) $foreign->getKey()]]);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($deleted->getArrayCopy())->toBe([(string) $mine->getKey()]);
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

it('refuses a source type no resolver is registered for before it asks any resolver', function (): void {
    try {
        ($this->createAction)()->execute(['subject' => 'Call back', 'source_type' => 'unknown_thing', 'source_id' => $this->mailId]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['source_type'])
            ->and($this->mails->accessChecks)->toBe([]);

        return;
    }

    $this->fail('a reminder accepted a source type nobody registered');
});

it('refuses a source identifier that is no ULID and a source without its other half', function (array $input, string $field): void {
    try {
        ($this->createAction)()->execute(['subject' => 'Call back', ...$input]);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    $this->fail('a reminder accepted a broken source');
})->with([
    'no ulid' => [['source_type' => 'email_message', 'source_id' => 'not-a-ulid'], 'source_id'],
    'type without id' => [['source_type' => 'email_message'], 'source_id'],
    'id without type' => [['source_id' => ModelStub::ulid('reminder-action-mail')], 'source_type'],
]);

it('refuses a source the acting user may not open and names the source field', function (): void {
    try {
        ($this->createAction)()->execute(['subject' => 'Call back', 'source_type' => 'email_message', 'source_id' => $this->foreignMailId]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['source_id'])
            ->and($this->mails->accessChecks)->toBe([$this->foreignMailId]);

        return;
    }

    $this->fail('a reminder linked a source the user may not open');
});

it('stores the source of a reminder created from an accessible source', function (): void {
    $row = ($this->insertedReminder)(['subject' => 'Call back', 'source_type' => 'email_message', 'source_id' => $this->mailId]);

    expect($row['source_type'])->toBe('email_message')
        ->and($row['source_id'])->toBe($this->mailId)
        ->and($this->mails->accessChecks)->toBe([$this->mailId]);
});

it('gives a reminder on a record no automatic record source', function (): void {
    $row = ($this->insertedReminder)(['subject' => 'Call back', 'record_id' => $this->recordId]);

    expect($row['record_id'])->toBe($this->recordId)
        ->and($row['source_type'] ?? null)->toBeNull()
        ->and($row['source_id'] ?? null)->toBeNull();
});

it('re-checks the source only when an update changes it', function (): void {
    $reminder = ($this->reminderWith)(['source_type' => 'email_message', 'source_id' => $this->foreignMailId]);

    StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "reminder_tasks"') ? [$reminder->getAttributes()] : [],
        fn (): int => 1,
    );

    try {
        ($this->updateAction)()->execute($reminder, ['subject' => 'Renamed']);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($this->mails->accessChecks)->toBe([])
        ->and(fn (): ReminderTask => ($this->updateAction)()->execute($reminder, ['source_type' => 'email_message', 'source_id' => ModelStub::ulid('reminder-action-other-mail')]))
        ->toThrow(ValidationException::class);
});

it('stores the rule of a repeating reminder and ends the series with the last second of its local end day', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);

    $row = ($this->insertedReminder)([
        'subject' => 'Weekly call',
        'due_at' => '2026-10-20T08:00:00Z',
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 2,
        'recurrence_ends_at' => '2026-12-31',
    ]);

    expect($row['recurrence_frequency'])->toBe('weekly')
        ->and($row['recurrence_interval'])->toBe(2)
        ->and($row['recurrence_ends_at'])->toBe('2026-12-31 22:59:59');
});

it('repeats a reminder every single period when the rule names no interval', function (): void {
    $row = ($this->insertedReminder)(['subject' => 'Daily check', 'due_at' => '2026-10-20T08:00:00Z', 'recurrence_frequency' => 'daily']);

    expect($row['recurrence_frequency'])->toBe('daily')
        ->and($row['recurrence_interval'])->toBe(1)
        ->and($row['recurrence_ends_at'])->toBeNull();
});

it('refuses a broken repetition at its own field before it writes anything', function (array $input, string $field): void {
    try {
        ($this->createAction)()->execute(['subject' => 'Call back', 'due_at' => '2026-10-20T08:00:00Z', ...$input]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);

        return;
    }

    $this->fail('a reminder accepted a broken repetition');
})->with([
    'unknown frequency' => [['recurrence_frequency' => 'hourly'], 'recurrence_frequency'],
    'interval zero' => [['recurrence_frequency' => 'daily', 'recurrence_interval' => 0], 'recurrence_interval'],
    'interval too large' => [['recurrence_frequency' => 'daily', 'recurrence_interval' => 100], 'recurrence_interval'],
    'no due date' => [['due_at' => null, 'recurrence_frequency' => 'daily'], 'recurrence_frequency'],
    'end before start' => [['recurrence_frequency' => 'daily', 'recurrence_ends_at' => '2026-10-19'], 'recurrence_ends_at'],
]);

it('tells the user in German that a repetition needs a due date', function (): void {
    app()->setLocale('de');

    try {
        ($this->createAction)()->execute(['subject' => 'Call back', 'recurrence_frequency' => 'daily']);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['recurrence_frequency' => ['Eine Wiederholung braucht einen Beginn.']]);

        return;
    }

    $this->fail('a series without a start was accepted');
});

it('ends the series of a reminder when an update clears its frequency', function (): void {
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 2, 'recurrence_ends_at' => '2026-12-31 22:59:59']);
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "reminder_tasks"') ? [$reminder->getAttributes()] : [],
        fn (): int => 1,
    );

    try {
        ($this->updateAction)()->execute($reminder, ['recurrence_frequency' => null]);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $update = ($this->writesOf)($connection, 'update "reminder_tasks"')[0] ?? null;

    expect($update['sql'] ?? '')->toContain('"recurrence_frequency" = ?', '"recurrence_interval" = ?', '"recurrence_ends_at" = ?')
        ->and(array_slice($update['bindings'] ?? [], 0, 3))->toBe([null, null, null]);
});

it('moves the anchor of a series but keeps its rule when an update only moves it', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 2, 'recurrence_ends_at' => '2026-12-31 22:59:59']);
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "reminder_tasks"') ? [$reminder->getAttributes()] : [],
        fn (): int => 1,
    );

    try {
        ($this->updateAction)()->execute($reminder, ['due_at' => '2026-10-21T08:00:00Z']);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $update = ($this->writesOf)($connection, 'update "reminder_tasks"')[0] ?? null;

    expect($update['sql'] ?? '')->toContain('"due_at" = ?', '"recurrence_anchor_at" = ?')
        ->and($update['sql'] ?? '')->not->toContain('"recurrence_frequency"', '"recurrence_interval"', '"recurrence_ends_at"', '"recurrence_exceptions"')
        ->and($update['bindings'] ?? [])->toContain('2026-10-21 08:00:00');
});

it('refuses to drop the due date of a reminder that still repeats', function (): void {
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1]);

    expect(fn (): ReminderTask => ($this->updateAction)()->execute($reminder, ['due_at' => null]))
        ->toThrow(ValidationException::class);
});

it('opens the next reminder of a series in the completing transaction with the same content and the rule moving along', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)([
        'due_at' => '2026-10-20 08:00:00',
        'done_at' => null,
        'note' => 'Agenda',
        'record_id' => $this->recordId,
        'reminder_type_id' => ModelStub::ulid('reminder-action-kind'),
        'assignee_id' => ModelStub::ulid('reminder-action-assignee'),
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_ends_at' => null,
    ]);

    $connection = ($this->completed)($reminder);
    $inserts = ($this->writesOf)($connection, 'insert into "reminder_tasks"');
    $row = ($this->rowOf)($inserts[0] ?? null);

    expect($inserts)->toHaveCount(1)
        ->and($connection->openedTransactions)->toBe(1)
        ->and($row)->toMatchArray([
            'tenant_id' => (string) $this->tenant->getKey(),
            'owner_id' => (string) $this->actor->getKey(),
            'creator_id' => (string) $this->actor->getKey(),
            'assignee_id' => ModelStub::ulid('reminder-action-assignee'),
            'record_id' => $this->recordId,
            'reminder_type_id' => ModelStub::ulid('reminder-action-kind'),
            'source_type' => 'email_message',
            'source_id' => $this->mailId,
            'due_at' => '2026-10-27 09:00:00',
            'subject' => 'Call back',
            'note' => 'Agenda',
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_ends_at' => null,
        ])
        ->and($row)->not->toHaveKey('done_at')
        ->and($this->timelineWriter->recorded)->toBe([ReminderEventState::Completed, ReminderEventState::Created]);
});

it('opens no further reminder once a series has reached its end or when the reminder does not repeat', function (array $attributes): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => null, ...$attributes]);

    $connection = ($this->completed)($reminder);

    expect(($this->writesOf)($connection, 'insert into "reminder_tasks"'))->toBe([])
        ->and($this->timelineWriter->recorded)->toBe([ReminderEventState::Completed]);
})->with([
    'series ended' => [['recurrence_frequency' => 'weekly', 'recurrence_interval' => 1, 'recurrence_ends_at' => '2026-10-25 22:59:59']],
    'single reminder' => [['recurrence_frequency' => null]],
]);

it('opens no second follow-up when a completed series reminder is completed again', function (): void {
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => '2026-10-20 09:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1]);

    $connection = ($this->completed)($reminder);

    expect(($this->writesOf)($connection, 'insert into "reminder_tasks"'))->toBe([])
        ->and($this->timelineWriter->recorded)->toBe([]);
});

it('opens no second follow-up when a competing request completed the series reminder after it was loaded', function (): void {
    $loaded = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => null, 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1]);
    $stored = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => '2026-10-20 09:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1]);

    $connection = ($this->running)($stored, fn () => ($this->completeAction)()->execute($loaded));

    $locked = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "reminder_tasks"') && str_contains($query['sql'], 'for update'));

    expect($locked)->not->toBeNull()
        ->and(($this->writesOf)($connection, 'insert into "reminder_tasks"'))->toBe([])
        ->and(($this->writesOf)($connection, 'update "reminder_tasks"'))->toBe([])
        ->and($this->timelineWriter->recorded)->toBe([]);
});

it('skips an excluded occurrence when it opens the next reminder of a series and hands on only the exclusions still ahead', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => null, 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1, 'recurrence_exceptions' => json_encode(['2026-10-13T08:00:00Z', '2026-10-27T09:00:00Z', '2026-11-17T09:00:00Z'])]);

    $row = ($this->rowOf)(($this->writesOf)(($this->completed)($reminder), 'insert into "reminder_tasks"')[0] ?? null);

    expect($row['due_at'])->toBe('2026-11-03 09:00:00')
        ->and(json_decode((string) $row['recurrence_exceptions'], true))->toBe(['2026-11-17T09:00:00Z']);
});

describe('a single occurrence of a series', function (): void {
    beforeEach(function (): void {
        config(['reports.timezone' => 'Europe/Berlin']);
        $this->series = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'done_at' => null, 'note' => 'Agenda', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1]);
    });

    it('moves a later occurrence into a reminder of its own that points back at the series and excludes it from the series', function (): void {
        $connection = ($this->running)($this->series, fn () => ($this->updateAction)()->execute($this->series, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-27T09:00:00Z', 'due_at' => '2026-10-28T09:00:00Z', 'subject' => 'Verschoben']));
        $row = ($this->rowOf)(($this->writesOf)($connection, 'insert into "reminder_tasks"')[0] ?? null);
        $update = ($this->writesOf)($connection, 'update "reminder_tasks"')[0] ?? null;

        expect($row)->toMatchArray([
            'series_id' => ModelStub::ulid('reminder-action-task'),
            'series_occurrence_at' => '2026-10-27 09:00:00',
            'due_at' => '2026-10-28 09:00:00',
            'subject' => 'Verschoben',
            'note' => 'Agenda',
            'recurrence_frequency' => null,
        ])
            ->and($update['sql'] ?? '')->toContain('"recurrence_exceptions" = ?')
            ->and($update['sql'] ?? '')->not->toContain('"due_at"', '"subject"')
            ->and($update['bindings'] ?? [])->toContain('["2026-10-27T09:00:00Z"]')
            ->and($this->timelineWriter->recorded)->toBe([ReminderEventState::Created]);
    });

    it('moves the current occurrence apart and lets the series go on with its next occurrence', function (): void {
        $connection = ($this->running)($this->series, fn () => ($this->updateAction)()->execute($this->series, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-20T08:00:00Z', 'due_at' => '2026-10-20T12:00:00Z']));
        $row = ($this->rowOf)(($this->writesOf)($connection, 'insert into "reminder_tasks"')[0] ?? null);
        $update = ($this->writesOf)($connection, 'update "reminder_tasks"')[0] ?? null;

        expect($row)->toMatchArray(['series_id' => ModelStub::ulid('reminder-action-task'), 'series_occurrence_at' => '2026-10-20 08:00:00', 'due_at' => '2026-10-20 12:00:00', 'subject' => 'Call back'])
            ->and($update['sql'] ?? '')->toContain('"due_at" = ?')
            ->and($update['bindings'] ?? [])->toContain('2026-10-27 09:00:00');
    });

    it('refuses an occurrence the series does not have and demands one when only a single occurrence is meant', function (array $input, string $field): void {
        try {
            ($this->running)($this->series, fn () => ($this->updateAction)()->execute($this->series, ['scope' => 'occurrence', 'due_at' => '2026-10-28T09:00:00Z', ...$input]));
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe([$field]);

            return;
        }

        $this->fail('a missing occurrence was accepted');
    })->with([
        'not an occurrence' => [['occurrence_at' => '2026-10-21T08:00:00Z'], 'occurrence_at'],
        'none named' => [[], 'occurrence_at'],
        'unknown scope' => [['scope' => 'everything', 'occurrence_at' => '2026-10-27T09:00:00Z'], 'scope'],
    ]);

    it('deletes a later occurrence by excluding it from the series', function (): void {
        $connection = ($this->running)($this->series, fn () => ($this->deleteAction)()->execute($this->series, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-27T09:00:00Z']));

        expect(($this->writesOf)($connection, 'delete from "reminder_tasks"'))->toBe([])
            ->and(($this->writesOf)($connection, 'update "reminder_tasks"')[0]['bindings'] ?? [])->toContain('["2026-10-27T09:00:00Z"]');
    });

    it('deletes the current occurrence by moving the series on to its next one and the last one by deleting the reminder', function (): void {
        $current = ($this->running)($this->series, fn () => ($this->deleteAction)()->execute($this->series, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-20T08:00:00Z']));
        $last = ($this->reminderWith)(['due_at' => '2026-10-20 08:00:00', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1, 'recurrence_ends_at' => '2026-10-25 22:59:59']);
        $ending = ($this->running)($last, fn () => ($this->deleteAction)()->execute($last, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-20T08:00:00Z']));

        expect(($this->writesOf)($current, 'delete from "reminder_tasks"'))->toBe([])
            ->and(($this->writesOf)($current, 'update "reminder_tasks"')[0]['bindings'] ?? [])->toContain('2026-10-27 09:00:00')
            ->and(($this->writesOf)($ending, 'delete from "reminder_tasks"'))->toHaveCount(1);
    });

    it('deletes the whole series together with its open reminders moved apart', function (): void {
        $moved = ($this->reminderWith)(['id' => ModelStub::ulid('reminder-action-moved'), 'series_id' => ModelStub::ulid('reminder-action-task'), 'due_at' => '2026-10-28 09:00:00']);

        $connection = ($this->running)($this->series, fn () => ($this->deleteAction)()->execute($this->series, ['scope' => 'series']), [$moved->getAttributes()]);
        $deletes = ($this->writesOf)($connection, 'delete from "reminder_tasks"');

        expect($deletes)->toHaveCount(2)
            ->and(array_merge(...array_column($deletes, 'bindings')))->toContain(ModelStub::ulid('reminder-action-task'), ModelStub::ulid('reminder-action-moved'));
    });
});

it('anchors a new series at its first due date', function (): void {
    $row = ($this->insertedReminder)(['subject' => 'Monatsabschluss', 'due_at' => '2027-01-31T09:00:00Z', 'recurrence_frequency' => 'monthly']);

    expect($row['recurrence_anchor_at'])->toBe('2027-01-31 09:00:00');
});

it('keeps a monthly series on its anchor day when a short month is completed', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2027-02-28 09:00:00', 'done_at' => null, 'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1, 'recurrence_anchor_at' => '2027-01-31 09:00:00']);

    $row = ($this->rowOf)(($this->writesOf)(($this->completed)($reminder), 'insert into "reminder_tasks"')[0] ?? null);

    expect($row['due_at'])->toBe('2027-03-31 08:00:00')
        ->and($row['recurrence_anchor_at'])->toBe('2027-01-31 09:00:00');
});

it('moves a monthly series on from its anchor when its current occurrence is deleted or moved apart', function (string $action): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2027-02-28 09:00:00', 'done_at' => null, 'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1, 'recurrence_anchor_at' => '2027-01-31 09:00:00']);
    $input = ['scope' => 'occurrence', 'occurrence_at' => '2027-02-28T09:00:00Z'];

    $connection = ($this->running)($reminder, fn () => $action === 'delete'
        ? ($this->deleteAction)()->execute($reminder, $input)
        : ($this->updateAction)()->execute($reminder, [...$input, 'due_at' => '2027-02-27T09:00:00Z']));
    $update = collect(($this->writesOf)($connection, 'update "reminder_tasks"'))->first(static fn (array $write): bool => str_contains($write['sql'], '"due_at"'));

    expect($update['bindings'] ?? [])->toContain('2027-03-31 08:00:00');
})->with(['delete', 'move']);

it('accepts only occurrences on the grid of the series anchor that do not lie before the current reminder', function (): void {
    config(['reports.timezone' => 'Europe/Berlin']);
    $reminder = ($this->reminderWith)(['due_at' => '2027-02-28 09:00:00', 'done_at' => null, 'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1, 'recurrence_anchor_at' => '2027-01-31 09:00:00']);

    $refused = function (string $occurrenceAt) use ($reminder): bool {
        try {
            ($this->running)($reminder, fn () => ($this->deleteAction)()->execute($reminder, ['scope' => 'occurrence', 'occurrence_at' => $occurrenceAt]));
        } catch (ValidationException) {
            return true;
        }

        return false;
    };

    expect($refused('2027-03-31T08:00:00Z'))->toBeFalse()
        ->and($refused('2027-03-28T08:00:00Z'))->toBeTrue()
        ->and($refused('2027-01-31T09:00:00Z'))->toBeTrue();
});

it('refuses a reference that exists only in another tenant and writes no reminder', function (string $field, string $table): void {
    $foreignTenantId = ModelStub::ulid('reminder-foreign-tenant');
    $referenceId = ModelStub::ulid("reminder-foreign-{$field}");

    $verifier = FakePresenceVerifier::holding([$table => [$referenceId]])->ownedByTenant($foreignTenantId);
    $connection = StaticQueryConnection::install(fn (): array => [], fn (): int => 1);

    try {
        expect(fn (): ReminderTask => ($this->createAction)()->execute(['subject' => 'Call back', $field => $referenceId]))
            ->toThrow(ValidationException::class);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($verifier->wasLookedUpWithin($table, (string) $this->tenant->getKey()))->toBeTrue()
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'reminder type' => ['reminder_type_id', 'reminder_types'],
    'assignee' => ['assignee_id', 'users'],
    'record' => ['record_id', 'custom_records'],
]);
