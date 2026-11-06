<?php

declare(strict_types=1);

use App\Actions\Goals\RecordGoalProgressAction;
use App\Models\Goal;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('goal-progress-tenant');
    $this->tenantId = (string) $this->tenant->getKey();

    $this->goal = ModelStub::make(Goal::class, [
        'id' => ModelStub::ulid('goal-progress-goal'),
        'tenant_id' => $this->tenantId,
    ]);

    $this->bounds = [
        'start' => CarbonImmutable::parse('2026-10-01 00:00:00'),
        'end' => CarbonImmutable::parse('2026-10-31 23:59:59'),
    ];

    $this->now = CarbonImmutable::parse('2026-10-15 08:00:00');

    $this->record = function (array $existingPeriods): StaticQueryConnection {
        $connection = StaticQueryConnection::install(
            static fn (string $sql): array => str_contains($sql, 'from "goal_periods"') ? $existingPeriods : [],
            static fn (): int => 1,
        );

        (new RecordGoalProgressAction)->execute($this->goal, $this->bounds, '42.50', $this->now, ['80' => 'fired']);

        return $connection;
    };

    $this->writtenRow = static function (array $statement): array {
        preg_match('/\(([^)]*)\) values/', $statement['sql'], $columns);

        return array_combine(
            array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $columns[1])),
            $statement['bindings'],
        );
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('looks for the period of the goal by its start inside the bound tenant', function (): void {
    $connection = ($this->record)([]);

    $lookups = array_values(array_filter(
        $connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "goal_periods"'),
    ));

    expect($lookups)->toHaveCount(1);

    $shape = new QueryShape($lookups[0]['sql'], $lookups[0]['bindings']);

    expect($shape->isScopedToTenant('goal_periods', $this->tenantId))->toBeTrue()
        ->and($shape->hasColumnCondition('goal_periods', 'goal_id'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->goal->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"period_start" = ?');
});

it('creates the period of a new start for the goal and its tenant with the measured value', function (): void {
    $connection = ($this->record)([]);

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toStartWith('insert into "goal_periods"');

    $row = ($this->writtenRow)($connection->writtenStatements[0]);

    expect($row['goal_id'])->toBe((string) $this->goal->getKey())
        ->and($row['tenant_id'])->toBe($this->tenantId)
        ->and($row['current_value'])->toBe('42.50')
        ->and($row['triggered_thresholds'])->toBe('{"80":"fired"}');
});

it('updates the existing period of the same start instead of adding a second one', function (): void {
    $connection = ($this->record)([[
        'id' => ModelStub::ulid('goal-progress-period'),
        'tenant_id' => $this->tenantId,
        'goal_id' => (string) $this->goal->getKey(),
        'period_start' => '2026-10-01 00:00:00',
        'period_end' => '2026-10-31 23:59:59',
        'current_value' => '10.00',
        'triggered_thresholds' => '{}',
    ]]);

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toStartWith('update "goal_periods"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain('42.50', ModelStub::ulid('goal-progress-period'));
});
