<?php

declare(strict_types=1);

use App\Support\Governance\AssignmentLoadCounter;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->counter = new AssignmentLoadCounter;

    $this->foreignTenantId = ModelStub::ulid('foreign-tenant');

    $this->circle = [ModelStub::ulid('approver-one'), ModelStub::ulid('approver-two')];

    /** @var callable():?QueryShape */
    $this->recordLookup = fn (): ?QueryShape => QueryShape::attemptedBy(
        fn (): array => $this->counter->countFor($this->circle, $this->foreignTenantId),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('counts nothing and asks the database nothing for an empty circle', function (): void {
    $counts = null;

    $shape = QueryShape::attemptedBy(function () use (&$counts): void {
        $counts = $this->counter->countFor([], (string) $this->tenant->getKey());
    });

    expect($shape)->toBeNull()
        ->and($counts)->toBe([]);
});

it('counts the open records of a circle inside the tenant it was handed and not the bound one', function (): void {
    $shape = ($this->recordLookup)();

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'tenant_id'))->toBeTrue()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse()
        ->and($shape->hasColumnCondition('custom_records', 'owner_id'))->toBeTrue()
        ->and($shape->hasBinding($this->circle[0]))->toBeTrue()
        ->and($shape->hasBinding($this->circle[1]))->toBeTrue();
});

it('leaves a trashed record out of the load and groups the count by its owner', function (): void {
    $shape = ($this->recordLookup)();

    expect($shape)->not->toBeNull()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('group by "custom_records"."owner_id"')
        ->and($shape->sql)->toContain('COUNT(*) as aggregate');
});

it('adds the open records and the undone tasks of each person of the circle inside the handed tenant and counts an unknown person as zero', function (): void {
    $idle = ModelStub::ulid('approver-idle');

    $connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "custom_records"') => [
            ['owner_id' => $this->circle[0], 'aggregate' => '2'],
            ['owner_id' => $this->circle[1], 'aggregate' => 1],
        ],
        str_contains($sql, 'from "reminder_tasks"') => [
            ['assignee_id' => $this->circle[0], 'aggregate' => '3'],
        ],
        default => [],
    });

    $counts = $this->counter->countFor([...$this->circle, $idle], $this->foreignTenantId);

    $tasks = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "reminder_tasks"'));

    expect($counts)->toBe([
        $this->circle[0] => 5,
        $this->circle[1] => 1,
        $idle => 0,
    ])
        ->and($connection->queries)->toHaveCount(2)
        ->and($tasks)->not->toBeNull()
        ->and($tasks['sql'])->toContain('"tenant_id" = ?')
        ->and($tasks['sql'])->toContain('"assignee_id" in')
        ->and($tasks['sql'])->toContain('"done_at" is null')
        ->and($tasks['sql'])->toContain('group by "assignee_id"')
        ->and($tasks['bindings'])->toContain($this->foreignTenantId)
        ->and($tasks['bindings'])->not->toContain((string) $this->tenant->getKey());
});
