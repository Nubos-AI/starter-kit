<?php

declare(strict_types=1);

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Models\ReminderTask;
use App\Support\Engine\RecordChangeRelayStarter;
use App\Support\Outbox\DatabaseOutboxWriter;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('outbox-tenant');
    $this->subjectId = ModelStub::ulid('outbox-subject');

    $this->relayStarter = Mockery::mock(RecordChangeRelayStarter::class);
    app()->instance(RecordChangeRelayStarter::class, $this->relayStarter);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('answers the outbox port with the writer that stores entries in the outbox table', function (): void {
    expect(app(OutboxWriter::class))->toBeInstanceOf(DatabaseOutboxWriter::class);
});

it('names a model as subject by its morph class and key', function (): void {
    $task = ModelStub::make(ReminderTask::class, ['id' => $this->subjectId]);

    expect(OutboxSubject::of($task))->toEqual(new OutboxSubject($task->getMorphClass(), $this->subjectId));
});

it('stores one entry with topic, tenant, subject and payload and asks for the relay after the commit', function (): void {
    $connection = StaticQueryConnection::install(fn (): array => [], fn (): int => 1);
    $this->relayStarter->shouldReceive('startAfterCommit')->once();

    app(OutboxWriter::class)->record('crm.example', $this->tenantId, new OutboxSubject('reminder_task', $this->subjectId), ['reason' => 'moved']);

    $inserts = $connection->writtenSqlOf('outbox_events');
    $bindings = $connection->writtenStatements[0]['bindings'];

    expect($inserts)->toHaveCount(1)
        ->and($inserts[0])->toStartWith('insert into "outbox_events"')
        ->and($inserts[0])->toContain('"topic"')
        ->and($inserts[0])->toContain('"subject_type"')
        ->and($inserts[0])->toContain('"subject_id"')
        ->and($inserts[0])->toContain('"payload"')
        ->and($inserts[0])->not->toContain('"record_id"')
        ->and($bindings)->toContain('crm.example')
        ->and($bindings)->toContain($this->tenantId)
        ->and($bindings)->toContain('reminder_task')
        ->and($bindings)->toContain($this->subjectId)
        ->and($bindings)->toContain('{"reason":"moved"}');
});

it('refuses the topic of record changes, which only the audit recorder writes', function (): void {
    $connection = StaticQueryConnection::install(fn (): array => [], fn (): int => 1);
    $this->relayStarter->shouldNotReceive('startAfterCommit');

    expect(fn () => app(OutboxWriter::class)->record('record.changed', $this->tenantId, new OutboxSubject('custom_record', $this->subjectId)))
        ->toThrow(InvalidArgumentException::class);

    expect($connection->writtenStatements)->toBe([]);
});
