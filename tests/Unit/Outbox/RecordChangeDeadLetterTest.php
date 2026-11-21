<?php

declare(strict_types=1);

use App\Support\Engine\OutboxRelay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'UTC'));

    config(['engine.outbox.record_max_attempts' => 3]);

    $this->relay = app(OutboxRelay::class);

    $this->event = static fn (string $seed, int $sequence, int $attempts, ?string $batchId = null): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => ModelStub::ulid('tenant'),
        'topic' => 'record.changed',
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid("record-{$seed}"),
        'relay_batch_id' => $batchId,
        'version' => 1,
        'sequence' => $sequence,
        'changed_field_keys' => '["name"]',
        'attempts' => $attempts,
        'created_at' => '2026-11-02 06:00:00',
        'available_at' => $batchId === null ? null : '2026-11-02 07:00:00',
    ];

    $this->expired = [];
    $this->pending = [];

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, '"relay_batch_id" = ?') => [],
            str_contains($sql, '"relay_batch_id" is not null') => $this->expired,
            str_contains($sql, '"relay_batch_id" is null') => $this->pending,
            default => [],
        },
        static fn (): int => 1,
    );

    /** @var callable(string):array<int, array{sql: string, bindings: list<mixed>}> */
    $this->writesContaining = fn (string $needle): array => array_values(array_filter(
        $this->connection->writtenStatements,
        static fn (array $statement): bool => str_contains($statement['sql'], $needle),
    ));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Carbon::setTestNow();
});

it('sets the events of a batch that kept failing aside as failed with an error log and releases the rest for a retry', function (): void {
    Log::spy();

    $this->expired = [
        ($this->event)('poisoned', 1, 3, 'old-run:1'),
        ($this->event)('healthy', 2, 1, 'old-run:1'),
    ];

    $this->relay->claim('new-run:1');

    $deadLettered = ($this->writesContaining)('"failed_at" = ?');
    $released = ($this->writesContaining)('"relay_batch_id" = ?, "available_at" = ?');

    expect($deadLettered)->toHaveCount(1)
        ->and($deadLettered[0]['bindings'])->toContain(ModelStub::ulid('poisoned'))
        ->and($deadLettered[0]['bindings'])->not->toContain(ModelStub::ulid('healthy'))
        ->and($released)->toHaveCount(1)
        ->and($released[0]['bindings'])->toBe([null, null, ModelStub::ulid('healthy')]);

    Log::shouldHaveReceived('error')->with(
        'Record changes failed on their last relay attempt and were set aside.',
        [
            'event_ids' => [ModelStub::ulid('poisoned')],
            'tenant_ids' => [ModelStub::ulid('tenant')],
            'relay_batch_ids' => ['old-run:1'],
            'max_attempts' => 3,
        ],
    )->once();
});

it('claims a change that already failed alone so it cannot hold back the healthy changes again', function (): void {
    $this->pending = [
        ($this->event)('retried', 1, 1),
        ($this->event)('fresh', 2, 0),
    ];

    $batch = $this->relay->claim('new-run:1');

    expect($batch->changes)->toHaveCount(1)
        ->and($batch->changes[0]->recordId)->toBe(ModelStub::ulid('record-retried'));
});

it('claims fresh changes up to the first one that already failed', function (): void {
    $this->pending = [
        ($this->event)('first', 1, 0),
        ($this->event)('second', 2, 0),
        ($this->event)('retried', 3, 2),
    ];

    $batch = $this->relay->claim('new-run:1');

    expect(array_map(static fn ($change): string => $change->recordId, $batch->changes))
        ->toBe([ModelStub::ulid('record-first'), ModelStub::ulid('record-second')]);
});

it('counts every claim of a change as one relay attempt', function (): void {
    $this->pending = [($this->event)('fresh', 1, 0)];

    $this->relay->claim('new-run:1');

    $claim = ($this->writesContaining)('"attempts" = "attempts" + 1');

    expect($claim)->toHaveCount(1)
        ->and($claim[0]['sql'])->toContain('"relay_batch_id" = ?')
        ->and($claim[0]['bindings'])->toContain('new-run:1');
});

it('never claims a change that was set aside as failed', function (): void {
    expect(QueryShape::of($this->relay->claimQuery([], 25))->sql)->toContain('"failed_at" is null');
});

it('sets a change aside that can never fit the relay payload instead of failing every claim behind it', function (): void {
    Log::spy();
    config(['engine.outbox.record_payload_bytes' => 1024]);

    $this->pending = [[...($this->event)('oversized', 1, 0), 'changed_field_keys' => json_encode([str_repeat('field', 1024)])]];

    $batch = $this->relay->claim('new-run:1');

    $deadLettered = ($this->writesContaining)('"failed_at" = ?');

    expect($batch->changes)->toBe([])
        ->and($deadLettered)->toHaveCount(1)
        ->and($deadLettered[0]['bindings'])->toContain(ModelStub::ulid('oversized'));

    Log::shouldHaveReceived('error')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'A record change exceeds the relay payload limit and was set aside.'
            && $context['event_id'] === ModelStub::ulid('oversized')
            && $context['limit'] === 1024)
        ->once();
});
