<?php

declare(strict_types=1);

use App\DTOs\Governance\CandidateCircle;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Governance\CandidateSource;
use App\Enums\Routing\RoutingStrategy;
use App\Handlers\Routing\RoundRobinStrategyHandler;
use App\Models\RoutingCursor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->automationId = ModelStub::ulid('cursor-automation');
    $this->handler = new RoundRobinStrategyHandler;

    /** @var callable(string):User */
    $this->user = fn (string $seed): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    /** @var callable(int, string):list<User> */
    $this->orderedUsers = function (int $count, string $prefix): array {
        /** @var list<User> $users */
        $users = [];

        for ($index = 0; $index < $count; $index++) {
            $users[] = ($this->user)("{$prefix}-{$index}");
        }

        usort($users, static fn (User $left, User $right): int => strcmp(
            (string) $left->getKey(),
            (string) $right->getKey(),
        ));

        return $users;
    };

    /** @var callable(string):RoutingContext */
    $this->context = fn (string $nodeId = 'assign-node'): RoutingContext => new RoutingContext(
        tenantId: (string) $this->tenant->getKey(),
        automationId: $this->automationId,
        nodeId: $nodeId,
        strategy: RoutingStrategy::RoundRobin,
        circle: new CandidateCircle(
            sources: [CandidateSource::FixedList],
            roleIds: [],
            teamIds: [],
            includeRecordTeam: false,
            fieldKey: null,
            userIds: [],
        ),
        skillId: null,
        skillFieldKey: null,
        at: CarbonImmutable::parse('2026-09-15T09:00:00+00:00'),
    );

    /** @var callable(?string):array<string, mixed> */
    $this->storedCursor = fn (?string $lastUserId): array => [
        'id' => ModelStub::ulid('stored-cursor'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'automation_id' => $this->automationId,
        'last_user_id' => $lastUserId,
        'node_id' => 'assign-node',
    ];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('consults the stored cursor before it picks anybody', function (): void {
    expect(WriteAttempt::reachedTheDatabase(fn () => $this->handler->pick(
        new Collection([($this->user)('cursor-single')]),
        ($this->context)('payment-node'),
    )))->toBeTrue();
});

it('never reaches for a cursor row when the circle is empty', function (): void {
    expect(WriteAttempt::reachedTheDatabase(fn () => $this->handler->pick(new Collection, ($this->context)())))
        ->toBeFalse()
        ->and($this->handler->pick(new Collection, ($this->context)()))->toBeNull();
});

it('hands the round robin strategy back as its own kind', function (): void {
    expect($this->handler->strategy())->toBe(RoutingStrategy::RoundRobin)
        ->and(RoutingCursor::query()->getModel()->getTable())->toBe('routing_cursors');
});

it('refuses a second cursor row for the same automation node', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000067_create_routing_cursors_table.php');

    expect($schema->has(
        'CREATE UNIQUE INDEX unq_routing_cursors_scope ON routing_cursors (tenant_id, automation_id, node_id)',
    ))->toBeTrue();
});

it('drops the cursor table again on the way down', function (): void {
    $schema = SchemaShape::ofMigration(
        'database/migrations/0001_01_01_000067_create_routing_cursors_table.php',
        'down',
    );

    expect($schema->has('drop table if exists "routing_cursors"'))->toBeTrue();
});

it('reads the cursor row of its own automation node under a row lock before it hands out the next person', function (): void {
    $ordered = ($this->orderedUsers)(2, 'locked');

    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "routing_cursors"')
            ? [($this->storedCursor)((string) $ordered[0]->getKey())]
            : [],
        static fn (): int => 1,
    );

    $picked = $this->handler->pick(new Collection($ordered), ($this->context)());

    expect((string) $picked?->getKey())->toBe((string) $ordered[1]->getKey())
        ->and($connection->openedTransactions)->toBe(1)
        ->and($connection->sqlOf('routing_cursors'))->toHaveCount(1)
        ->and($connection->queries[0]['sql'])->toContain('for update')
        ->and($connection->queries[0]['bindings'])->toContain(
            (string) $this->tenant->getKey(),
            $this->automationId,
            'assign-node',
        );
});

it('starts the claim over once a competing node has inserted the very same cursor row', function (): void {
    $ordered = ($this->orderedUsers)(2, 'race');
    $attempts = 0;

    $connection = StaticQueryConnection::install(
        function (string $sql) use (&$attempts, $ordered): array {
            if (!str_contains($sql, 'from "routing_cursors"')) {
                return [];
            }

            return $attempts === 0 ? [] : [($this->storedCursor)((string) $ordered[0]->getKey())];
        },
        function (string $sql) use (&$attempts): int {
            $attempts++;

            if ($attempts === 1) {
                throw new UniqueConstraintViolationException('static', $sql, [], new PDOException('duplicate key'));
            }

            return 1;
        },
    );

    $picked = $this->handler->pick(new Collection($ordered), ($this->context)());

    expect((string) $picked?->getKey())->toBe((string) $ordered[1]->getKey())
        ->and($attempts)->toBe(2)
        ->and($connection->openedTransactions)->toBe(2)
        ->and($connection->writtenSqlOf('routing_cursors'))->toHaveCount(2);
});
