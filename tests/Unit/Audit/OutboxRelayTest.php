<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordChangeBatch;
use App\Exceptions\Outbox\OutboxClaimLostException;
use App\Exceptions\Outbox\OutboxPayloadTooLargeException;
use App\Models\OutboxEvent;
use App\Support\Engine\OutboxRelay;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->relay = app(OutboxRelay::class);
    $this->lockedTenantId = ModelStub::ulid('locked-tenant');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('claims only unpublished events, oldest sequence first, and never the same row twice', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([], 25));

    expect($shape->targets('outbox_events'))->toBeTrue()
        ->and($shape->sql)->toContain('"published_at" is null')
        ->and($shape->sql)->toContain('order by "sequence" asc')
        ->and($shape->sql)->toContain('limit 25')
        ->and($shape->sql)->toContain('for update skip locked');
});

it('leaves the events of a tenant under maintenance in the outbox', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([$this->lockedTenantId], 25));

    expect($shape->sql)->toContain('"tenant_id" not in (?)')
        ->and($shape->hasBinding($this->lockedTenantId))->toBeTrue();
});

it('adds no tenant condition at all while no tenant is under maintenance', function (): void {
    expect(QueryShape::of($this->relay->claimQuery([], 25))->sql)->not->toContain('tenant_id');
});

it('reads the outbox across every tenant, unbound by the tenant scope', function (): void {
    AccessContext::tenant();

    expect(QueryShape::of($this->relay->claimQuery([], 25))->sql)->not->toContain('"outbox_events"."tenant_id" =');
});

it('claims only record changes and leaves the entries of every other topic to their handlers', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([], 25));

    expect($shape->sql)->toContain('"topic" = ?')
        ->and($shape->hasBinding('record.changed'))->toBeTrue();
});

it('excludes active claims but can recover expired leases', function (): void {
    $shape = QueryShape::of($this->relay->claimQuery([], 25));

    expect($shape->sql)->toContain('"available_at" is null or "available_at" <= ?');
});

it('bounds the serialized record payload while keeping event order', function (): void {
    config(['engine.outbox.record_payload_bytes' => 1024]);
    $events = new Collection;

    for ($sequence = 1; $sequence <= 20; $sequence++) {
        $events->push((new OutboxEvent)->forceFill([
            'id' => ModelStub::ulid("event-{$sequence}"),
            'tenant_id' => ModelStub::ulid('tenant'),
            'object_type_id' => ModelStub::ulid('type'),
            'record_id' => ModelStub::ulid('record'),
            'sequence' => $sequence,
            'version' => 1,
            'changed_field_keys' => ['title'],
        ]));
    }

    $selected = $this->relay->withinPayloadLimit($events, 'run:activity');
    $batch = RecordChangeBatch::fromArray($selected->map(fn (OutboxEvent $event): array => $event->toArray())->all());
    $payload = (new RecordChangeBatch($batch->changes, 'run:activity'))->toTemporalPayload();

    expect($selected->count())->toBeGreaterThan(0)->toBeLessThan(20)
        ->and($selected->pluck('sequence')->all())->toBe(range(1, $selected->count()))
        ->and(strlen(json_encode($payload, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(1024);
});

it('does not silently skip an individually oversized record change', function (): void {
    config(['engine.outbox.record_payload_bytes' => 1024]);
    $event = (new OutboxEvent)->forceFill([
        'id' => ModelStub::ulid('large-event'),
        'tenant_id' => ModelStub::ulid('tenant'),
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid('record'),
        'sequence' => 1,
        'version' => 1,
        'changed_field_keys' => [str_repeat('field', 1024)],
    ]);

    expect(fn () => $this->relay->withinPayloadLimit(new Collection([$event]), 'run:activity'))
        ->toThrow(OutboxPayloadTooLargeException::class);
});

it('round trips durable batches and still reads historical temporal payloads', function (): void {
    $data = [[
        'tenant_id' => ModelStub::ulid('tenant'),
        'object_type_id' => ModelStub::ulid('type'),
        'record_id' => ModelStub::ulid('record'),
        'sequence' => 1,
        'version' => 2,
        'changed_field_keys' => ['title'],
    ]];
    $legacy = RecordChangeBatch::fromTemporalPayload($data);
    $batch = new RecordChangeBatch($legacy->changes, 'run:activity');
    $restored = RecordChangeBatch::fromTemporalPayload($batch->toTemporalPayload());

    expect($legacy->relayBatchId)->toBeNull()
        ->and($restored->relayBatchId)->toBe('run:activity')
        ->and($restored->changes[0]->toArray())->toBe($legacy->changes[0]->toArray());
});

it('refuses to renew or acknowledge a batch when only part of its membership is still owned', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [[
        'id' => ModelStub::ulid('remaining-event'),
        'relay_batch_id' => 'run:7',
    ]]);

    expect($this->relay->renew('run:7', 2))->toBeFalse()
        ->and(fn () => $this->relay->acknowledge('run:7', 2))->toThrow(OutboxClaimLostException::class)
        ->and($connection->writtenStatements)->toBe([]);
});

it('keeps empty claims from being treated as owned batches', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);

    expect($this->relay->renew('run:7', 0))->toBeFalse()
        ->and(fn () => $this->relay->acknowledge('run:7', 0))->toThrow(OutboxClaimLostException::class)
        ->and($connection->writtenStatements)->toBe([]);
});
