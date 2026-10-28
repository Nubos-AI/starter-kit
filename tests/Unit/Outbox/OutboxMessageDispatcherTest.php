<?php

declare(strict_types=1);

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Outbox\OutboxSubject;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Outbox\OutboxHandlerRegistry;
use App\Support\Outbox\OutboxMessageDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 12:00:00');

    config()->set('engine.outbox.batch_size', 50);
    config()->set('engine.outbox.message_lease_seconds', 300);
    config()->set('engine.outbox.message_retry_seconds', 30);
    config()->set('engine.outbox.message_max_attempts', 3);

    $this->tenantId = ModelStub::ulid('dispatch-tenant');
    $this->lockedTenantId = ModelStub::ulid('dispatch-locked-tenant');

    $this->handled = new stdClass;
    $this->handled->messages = [];

    $this->handler = function (string $topic, ?Throwable $failure = null): IdempotentOutboxHandler {
        return new class($topic, $failure, $this->handled) implements IdempotentOutboxHandler
        {
            public function __construct(private readonly string $handledTopic, private readonly ?Throwable $failure, private readonly stdClass $handled) {}

            public function topic(): string
            {
                return $this->handledTopic;
            }

            public function handle(OutboxMessage $message): void
            {
                $this->handled->messages[] = $message;

                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }
            }
        };
    };

    $this->row = fn (string $seed, string $topic, string $subjectSeed, int $attempts = 0, array $payload = []): array => [
        'id' => ModelStub::ulid($seed),
        'sequence' => 1,
        'tenant_id' => $this->tenantId,
        'topic' => $topic,
        'subject_type' => 'custom_record',
        'subject_id' => ModelStub::ulid($subjectSeed),
        'payload' => json_encode($payload),
        'attempts' => $attempts,
        'published_at' => null,
        'failed_at' => null,
    ];

    $locks = Mockery::mock(MaintenanceLockRegistry::class);
    $locks->shouldReceive('lockedTenantIds')->andReturn([])->byDefault();
    $this->locks = $locks;

    $this->dispatcher = fn (array $handlers): OutboxMessageDispatcher => new OutboxMessageDispatcher($this->locks, new OutboxHandlerRegistry($handlers));

    $this->install = fn (array $rows): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "outbox_events"') ? $rows : [],
        fn (string $sql, array $bindings): int => 1,
    );

    $this->updatesSetting = fn (StaticQueryConnection $connection, string $column): array => array_values(array_map(
        static fn (array $statement): array => [
            'sql' => $statement['sql'],
            'bindings' => array_map(static fn (mixed $binding): mixed => $binding instanceof DateTimeInterface ? $binding->format('Y-m-d H:i:s') : $binding, $statement['bindings']),
        ],
        array_filter(
            $connection->writtenStatements,
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "outbox_events" set')
                && str_contains(explode(' where ', $statement['sql'])[0], "\"{$column}\" ="),
        ),
    ));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Carbon::setTestNow();
    Mockery::close();
});

it('claims only pending entries of the topics, oldest first, never a record change and never the same row twice', function (): void {
    $shape = QueryShape::of(($this->dispatcher)([])->claimQuery([], 25));

    expect($shape->targets('outbox_events'))->toBeTrue()
        ->and($shape->sql)->toContain('"topic" <> ?')
        ->and($shape->hasBinding('record.changed'))->toBeTrue()
        ->and($shape->sql)->toContain('"published_at" is null')
        ->and($shape->sql)->toContain('"failed_at" is null')
        ->and($shape->sql)->toContain('("available_at" is null or "available_at" <= ?)')
        ->and($shape->sql)->toContain('order by "sequence" asc')
        ->and($shape->sql)->toContain('limit 25')
        ->and($shape->sql)->toContain('for update skip locked');
});

it('leaves the entries of a tenant under maintenance in the outbox', function (): void {
    $shape = QueryShape::of(($this->dispatcher)([])->claimQuery([$this->lockedTenantId], 25));

    expect($shape->sql)->toContain('"tenant_id" not in (?)')
        ->and($shape->hasBinding($this->lockedTenantId))->toBeTrue();
});

it('hands every claimed entry to the handler of its topic and marks it published', function (): void {
    $connection = ($this->install)([
        ($this->row)('first', 'crm.first', 'deal-a'),
        ($this->row)('second', 'crm.second', 'task-b'),
    ]);

    $outcome = ($this->dispatcher)([($this->handler)('crm.first'), ($this->handler)('crm.second')])->dispatchPending();

    $published = ($this->updatesSetting)($connection, 'published_at');

    expect(array_map(static fn (OutboxMessage $message): string => "{$message->topic}:{$message->subject->id}", $this->handled->messages))
        ->toBe(['crm.first:'.ModelStub::ulid('deal-a'), 'crm.second:'.ModelStub::ulid('task-b')])
        ->and($this->handled->messages[0]->tenantId)->toBe($this->tenantId)
        ->and($this->handled->messages[0]->subject)->toEqual(new OutboxSubject('custom_record', ModelStub::ulid('deal-a')))
        ->and($outcome->handled)->toBe(2)
        ->and($outcome->retried)->toBe(0)
        ->and($outcome->failed)->toBe(0)
        ->and($published)->toHaveCount(1)
        ->and($published[0]['bindings'])->toContain(ModelStub::ulid('first'))
        ->and($published[0]['bindings'])->toContain(ModelStub::ulid('second'));
});

it('leases every claimed entry before a handler runs so a crashed run hands it out again', function (): void {
    $connection = ($this->install)([($this->row)('first', 'crm.first', 'deal-a')]);

    ($this->dispatcher)([($this->handler)('crm.first')])->dispatchPending();

    $leases = ($this->updatesSetting)($connection, 'available_at');

    expect($leases[0]['sql'])->toContain('"attempts" = "attempts" + 1')
        ->and($leases[0]['bindings'])->toContain('2026-10-05 12:05:00')
        ->and($leases[0]['bindings'])->toContain(ModelStub::ulid('first'));
});

it('hands identical entries of one batch to the handler once, because handling twice equals handling once', function (): void {
    $connection = ($this->install)([
        ($this->row)('first', 'crm.first', 'deal-a'),
        ($this->row)('second', 'crm.first', 'deal-a'),
        ($this->row)('third', 'crm.first', 'deal-a', 0, ['reason' => 'other']),
    ]);

    $outcome = ($this->dispatcher)([($this->handler)('crm.first')])->dispatchPending();

    $published = ($this->updatesSetting)($connection, 'published_at');

    expect($this->handled->messages)->toHaveCount(2)
        ->and($outcome->handled)->toBe(3)
        ->and($published[0]['bindings'])->toContain(ModelStub::ulid('second'));
});

it('retries the entries of a failing handler later without blocking the other topics', function (): void {
    Log::spy();
    $connection = ($this->install)([
        ($this->row)('failing', 'crm.failing', 'deal-a'),
        ($this->row)('working', 'crm.working', 'deal-b'),
    ]);

    $outcome = ($this->dispatcher)([
        ($this->handler)('crm.failing', new RuntimeException('temporal is unreachable')),
        ($this->handler)('crm.working'),
    ])->dispatchPending();

    $published = ($this->updatesSetting)($connection, 'published_at');
    $retries = array_values(array_filter(($this->updatesSetting)($connection, 'available_at'), static fn (array $statement): bool => !str_contains($statement['sql'], '"attempts"')));

    expect($outcome->handled)->toBe(1)
        ->and($outcome->retried)->toBe(1)
        ->and($published)->toHaveCount(1)
        ->and($published[0]['bindings'])->toContain(ModelStub::ulid('working'))
        ->and($published[0]['bindings'])->not->toContain(ModelStub::ulid('failing'))
        ->and($retries)->toHaveCount(1)
        ->and($retries[0]['bindings'])->toContain('2026-10-05 12:00:30')
        ->and($retries[0]['bindings'])->toContain(ModelStub::ulid('failing'));

    Log::shouldHaveReceived('warning')->with('Outbox handler failed; the entry is retried.', Mockery::on(fn (array $context): bool => $context['topic'] === 'crm.failing'
        && $context['tenant_id'] === $this->tenantId
        && $context['subject_id'] === ModelStub::ulid('deal-a')
        && $context['attempts'] === 1
        && $context['exception'] === RuntimeException::class));
});

it('gives up on an entry whose handler failed on its last attempt and logs it as an error', function (): void {
    Log::spy();
    $connection = ($this->install)([($this->row)('failing', 'crm.failing', 'deal-a', 2)]);

    $outcome = ($this->dispatcher)([($this->handler)('crm.failing', new RuntimeException('still down'))])->dispatchPending();

    $failed = ($this->updatesSetting)($connection, 'failed_at');

    expect($outcome->failed)->toBe(1)
        ->and($outcome->retried)->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['bindings'])->toContain(ModelStub::ulid('failing'));

    Log::shouldHaveReceived('error')->with('Outbox handler failed on its last attempt; the entry is given up.', Mockery::on(fn (array $context): bool => $context['topic'] === 'crm.failing' && $context['attempts'] === 3));
});

it('sets an entry aside whose topic no installed module handles', function (): void {
    Log::spy();
    $connection = ($this->install)([($this->row)('orphan', 'crm.removed', 'deal-a')]);

    $outcome = ($this->dispatcher)([])->dispatchPending();

    expect($outcome->failed)->toBe(1)
        ->and(($this->updatesSetting)($connection, 'failed_at')[0]['bindings'])->toContain(ModelStub::ulid('orphan'));

    Log::shouldHaveReceived('warning')->with('Outbox entry has no handler for its topic; the entry is set aside.', Mockery::on(fn (array $context): bool => $context['topic'] === 'crm.removed'));
});

it('touches nothing when no entry is pending', function (): void {
    $connection = ($this->install)([]);

    $outcome = ($this->dispatcher)([($this->handler)('crm.first')])->dispatchPending();

    expect($outcome->handled + $outcome->retried + $outcome->failed)->toBe(0)
        ->and($connection->writtenStatements)->toBe([]);
});
