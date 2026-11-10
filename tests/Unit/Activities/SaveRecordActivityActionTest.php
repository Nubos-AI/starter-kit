<?php

declare(strict_types=1);

use App\Actions\Activities\SaveRecordActivityAction;
use App\Models\CustomRecord;
use App\Models\RecordActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RefusingSourceLinkResolver;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticSourceLinkResolver;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->recordTenantId = ModelStub::ulid('owning-tenant');
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->action = app(SaveRecordActivityAction::class);
    $this->activityTypeId = ModelStub::ulid('activity-type');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('activity-record'),
        'tenant_id' => $this->recordTenantId,
    ]);

    $this->trashedRecord = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('trashed-activity-record'),
        'tenant_id' => $this->recordTenantId,
        'deleted_at' => '2026-09-01 00:00:00',
    ]);

    $this->input = [
        'subject' => 'Rückruf',
        'occurred_at' => '2026-09-19T10:00:00+02:00',
        'activity_type_id' => $this->activityTypeId,
        'assignee_id' => (string) $this->actor->getKey(),
    ];

    $this->mailId = ModelStub::ulid('activity-mail');
    $this->foreignMailId = ModelStub::ulid('activity-foreign-mail');
    $this->mails = new StaticSourceLinkResolver('email_message', [], [$this->mailId]);
    config(['links.sources.email_message' => StaticSourceLinkResolver::class]);
    app()->instance(StaticSourceLinkResolver::class, $this->mails);

    $this->standalone = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('standalone-activity'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'record_id' => null,
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        'activity_type_id' => $this->activityTypeId,
    ]);

    $this->knownRows = fn (): FakePresenceVerifier => FakePresenceVerifier::holding([
        'activity_types' => [$this->activityTypeId],
        'users' => [(string) $this->actor->getKey()],
    ]);

    $this->connectionAccepting = fn (): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'count(*) as aggregate') ? [['aggregate' => 1]] : [],
        fn (): int => 1,
    );

    /** @var callable(array<string, mixed>, ?RecordActivity):?QueryShape */
    $this->lookupFor = fn (array $input, ?RecordActivity $activity = null): ?QueryShape => QueryShape::attemptedBy(
        fn (): mixed => $this->action->execute($this->actor, $this->record, $input, $activity),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('asks the policy for the record before it reads or validates anything and reads nothing when the policy refuses', function (): void {
    $gate = GateSpy::allowing();

    $refusal = null;

    $shape = QueryShape::attemptedBy(function () use (&$refusal): void {
        try {
            $this->action->execute($this->actor, $this->record, $this->input);
        } catch (AuthorizationException $exception) {
            $refusal = $exception;
        }
    });

    expect($shape)->toBeNull()
        ->and($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('update')
        ->and($gate->calls[0]['arguments'][0])->toBe($this->record);
});

it('refuses to add an activity to a record that is in the trash', function (): void {
    GateSpy::allowing('update');

    expect(fn (): mixed => $this->action->execute($this->actor, $this->trashedRecord, $this->input))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses to move an existing activity onto a different record', function (): void {
    GateSpy::allowing('update');

    $foreign = ModelStub::make(RecordActivity::class, [
        'record_id' => ModelStub::ulid('some-other-record'),
        'activity_type_id' => $this->activityTypeId,
    ]);

    expect(fn (): mixed => $this->action->execute($this->actor, $this->record, $this->input, $foreign))
        ->toThrow(NotFoundHttpException::class);
});

it('accepts an activity type only from the tenant the record belongs to, not the bound one', function (): void {
    GateSpy::allowing('update');

    $shape = ($this->lookupFor)($this->input);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('activity_types'))->toBeTrue()
        ->and($shape->hasBinding($this->activityTypeId))->toBeTrue()
        ->and($shape->hasBinding($this->recordTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('hides a retired activity type from a new activity', function (): void {
    GateSpy::allowing('update');

    expect(($this->lookupFor)($this->input)->sql)->toContain('"deleted_at" is null');
});

it('keeps a retired activity type usable on the activity that already carries it', function (): void {
    GateSpy::allowing('update');

    $existing = ModelStub::make(RecordActivity::class, [
        'record_id' => $this->record->getKey(),
        'activity_type_id' => $this->activityTypeId,
    ]);

    expect(($this->lookupFor)($this->input, $existing)->sql)->not->toContain('"deleted_at" is null');
});

it('accepts an assignee only from the tenant the record belongs to and only while the account lives', function (): void {
    GateSpy::allowing('update');

    $shape = ($this->lookupFor)([...$this->input, 'activity_type_id' => ['not a string']]);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->actor->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->recordTenantId))->toBeTrue()
        ->and($shape->sql)->toContain('"deleted_at" is null');
});

it('refuses an activity without a subject, without a date, or with an unusable date', function (): void {
    GateSpy::allowing('update');

    /** @var callable(array<string, mixed>):array<string, list<string>> */
    $errorsOf = function (array $input): array {
        try {
            $this->action->execute($this->actor, $this->record, $input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted an activity it should have refused');
    };

    $bare = $errorsOf(['activity_type_id' => ['skip the lookup']]);

    expect($bare)->toHaveKey('subject')
        ->and($bare)->toHaveKey('occurred_at')
        ->and($bare)->toHaveKey('assignee_id')
        ->and($errorsOf([
            'activity_type_id' => ['skip the lookup'],
            'subject' => str_repeat('a', 256),
            'occurred_at' => 'not a date',
        ]))->toHaveKeys(['subject', 'occurred_at']);
});

it('asks the policy whether the actor may log a standalone activity before it validates anything', function (): void {
    $gate = GateSpy::allowing();

    expect(fn (): mixed => $this->action->execute($this->actor, null, $this->input))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('create')
        ->and($gate->calls[0]['arguments'][0])->toBe(RecordActivity::class)
        ->and($this->mails->accessChecks)->toBe([]);
});

it('demands a source from an activity that belongs to no record', function (): void {
    GateSpy::allowing('create');
    ($this->knownRows)();

    try {
        $this->action->execute($this->actor, null, [...$this->input, 'activity_type_id' => ['skip the lookup']]);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('source_type');

        return;
    }

    $this->fail('a standalone activity was accepted without a source');
});

it('refuses a standalone activity on a source the actor may not open', function (): void {
    GateSpy::allowing('create');
    ($this->knownRows)();
    ($this->connectionAccepting)();

    try {
        $this->action->execute($this->actor, null, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->foreignMailId]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['source_id'])
            ->and($this->mails->accessChecks)->toBe([$this->foreignMailId]);

        return;
    }

    $this->fail('a standalone activity linked a source the actor may not open');
});

it('checks the activity type of a standalone activity against the bound tenant', function (): void {
    GateSpy::allowing('create');

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->action->execute($this->actor, null, [
        ...$this->input,
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
    ]));

    expect($shape?->targets('activity_types'))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('stores a standalone activity with its source and writes no timeline entry', function (): void {
    GateSpy::allowing('create');
    ($this->knownRows)();
    $connection = ($this->connectionAccepting)();

    $this->action->execute($this->actor, null, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->mailId]);

    $insert = collect($connection->writtenStatements)
        ->first(fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "record_activities"'));

    expect($insert)->not->toBeNull()
        ->and($insert['bindings'])->toContain('email_message')
        ->and($insert['bindings'])->toContain($this->mailId)
        ->and($insert['bindings'])->toContain((string) $this->tenant->getKey())
        ->and(array_combine(
            array_map(fn (string $column): string => trim($column, ' "'), explode(',', Str::between($insert['sql'], '(', ') values'))),
            $insert['bindings'],
        )['record_id'])->toBeNull()
        ->and($connection->writtenSqlOf('timeline_entries'))->toBe([])
        ->and($connection->sqlOf('timeline_entries'))->toBe([]);
});

it('keeps a record bound activity free of an automatic record source', function (): void {
    GateSpy::allowing('update');
    ($this->knownRows)();
    $connection = ($this->connectionAccepting)();

    $this->action->execute($this->actor, $this->record, $this->input);

    $insert = collect($connection->writtenStatements)
        ->first(fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "record_activities"'));

    expect($insert)->not->toBeNull()
        ->and($insert['sql'])->not->toContain('"source_type"');
});

it('lets only the policy of the activity itself decide about changing a standalone activity', function (): void {
    $gate = GateSpy::allowing();

    expect(fn (): mixed => $this->action->execute($this->actor, null, $this->input, $this->standalone))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('update')
        ->and($gate->calls[0]['arguments'][0])->toBe($this->standalone);
});

it('refuses to change a record bound activity through the standalone path', function (): void {
    GateSpy::allowing('update');

    $bound = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('bound-activity'),
        'record_id' => $this->record->getKey(),
    ]);

    expect(fn (): mixed => $this->action->execute($this->actor, null, $this->input, $bound))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses an activity whose source names a reason against the target and writes nothing', function (): void {
    GateSpy::allowing('update');
    ($this->knownRows)();
    $connection = ($this->connectionAccepting)();
    $rule = new RefusingSourceLinkResolver('email_message', [$this->mailId], [$this->mailId => 'Die E-Mail ist nicht mit diesem Datensatz verknüpft.']);
    app()->instance(StaticSourceLinkResolver::class, $rule);

    try {
        $this->action->execute($this->actor, $this->record, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->mailId]);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['source_id' => ['Die E-Mail ist nicht mit diesem Datensatz verknüpft.']])
            ->and($rule->activityChecks)->toBe([['source_id' => $this->mailId, 'record_id' => $this->record->getKey()]])
            ->and($connection->writtenSqlOf('record_activities'))->toBe([]);

        return;
    }

    $this->fail('an activity was stored although its source refused the target');
});

it('asks the source about a standalone activity as well', function (): void {
    GateSpy::allowing('create');
    ($this->knownRows)();
    ($this->connectionAccepting)();
    $rule = new RefusingSourceLinkResolver('email_message', [$this->mailId], [$this->mailId => 'Verknüpfen Sie die E-Mail zuerst mit einem Datensatz.']);
    app()->instance(StaticSourceLinkResolver::class, $rule);

    expect(fn (): mixed => $this->action->execute($this->actor, null, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->mailId]))
        ->toThrow(ValidationException::class, 'Verknüpfen Sie die E-Mail zuerst mit einem Datensatz.')
        ->and($rule->activityChecks)->toBe([['source_id' => $this->mailId, 'record_id' => null]]);
});

it('stores the activity when its source accepts the target', function (): void {
    GateSpy::allowing('update');
    ($this->knownRows)();
    $connection = ($this->connectionAccepting)();
    $rule = new RefusingSourceLinkResolver('email_message', [$this->mailId]);
    app()->instance(StaticSourceLinkResolver::class, $rule);

    $this->action->execute($this->actor, $this->record, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->mailId]);

    expect($rule->activityChecks)->toHaveCount(1)
        ->and($connection->writtenSqlOf('record_activities'))->not->toBe([]);
});

it('leaves an existing activity alone when its source stays the same', function (): void {
    GateSpy::allowing('update');
    ($this->knownRows)();
    ($this->connectionAccepting)();
    $rule = new RefusingSourceLinkResolver('email_message', [$this->mailId], [$this->mailId => 'Die E-Mail ist nicht mit diesem Datensatz verknüpft.']);
    app()->instance(StaticSourceLinkResolver::class, $rule);

    $existing = ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('linked-activity'),
        'record_id' => $this->record->getKey(),
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        'activity_type_id' => $this->activityTypeId,
    ]);

    $this->action->execute($this->actor, $this->record, [...$this->input, 'source_type' => 'email_message', 'source_id' => $this->mailId], $existing);

    expect($rule->activityChecks)->toBe([]);
});
