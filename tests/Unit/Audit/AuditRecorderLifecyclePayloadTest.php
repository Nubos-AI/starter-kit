<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Support\Approvals\ApprovalInvalidator;
use App\Support\Audit\ActorResolver;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RecordChangeRelayStarter;
use App\Support\Engine\RecordDiff;
use App\Support\Timeline\ChangeTimelineWriter;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('audited-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('audited-object-type'),
    ]);

    $actor = Mockery::mock(ActorResolver::class);
    $actor->shouldReceive('resolve')->andReturn([null, null]);
    $actor->shouldReceive('apiTokenId')->andReturn(null);

    $encryptedKeys = Mockery::mock(EncryptedFieldKeys::class);
    $encryptedKeys->shouldReceive('forObjectType')->andReturn([]);

    $timeline = Mockery::mock(ChangeTimelineWriter::class);
    $timeline->shouldReceive('record');

    $approvals = Mockery::mock(ApprovalInvalidator::class);
    $approvals->shouldReceive('handleChange');

    $relay = Mockery::mock(RecordChangeRelayStarter::class);
    $relay->shouldReceive('startAfterCommit');

    $this->recorder = new AuditRecorder($actor, app(RecordDiff::class), $relay, $timeline, $encryptedKeys, $approvals);

    $this->connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    /** @var callable():list<mixed> */
    $this->outboxBindings = function (): array {
        $outbox = array_values(array_filter(
            $this->connection->writtenStatements,
            static fn (array $statement): bool => str_contains($statement['sql'], 'insert into "outbox_events"'),
        ));

        expect($outbox)->toHaveCount(1);

        return $outbox[0]['bindings'];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('marks the outbox entry of a soft delete as a deletion', function (): void {
    $this->recorder->record($this->record, ['deleted_at' => null], ['deleted_at' => '2026-09-01T08:00:00+00:00'], 3);

    expect(($this->outboxBindings)())->toContain(json_encode(['deleted' => true], JSON_THROW_ON_ERROR));
});

it('marks the outbox entry of a restore as no deletion', function (): void {
    $this->recorder->record($this->record, ['deleted_at' => '2026-09-01T08:00:00+00:00'], ['deleted_at' => null], 3);

    expect(($this->outboxBindings)())->toContain(json_encode(['deleted' => false], JSON_THROW_ON_ERROR));
});

it('carries no lifecycle marker on an ordinary field change', function (): void {
    $this->recorder->record($this->record, ['amount' => 1], ['amount' => 2], 3);

    expect(($this->outboxBindings)())->not->toContain(json_encode(['deleted' => true], JSON_THROW_ON_ERROR))
        ->and(($this->outboxBindings)())->not->toContain(json_encode(['deleted' => false], JSON_THROW_ON_ERROR));
});
