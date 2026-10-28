<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordCountData;
use App\Models\CustomRecord;
use App\Support\Engine\RecordCountEstimator;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    AccessContext::suspendRowAccess();

    $this->objectTypeId = ModelStub::ulid('estimated-contacts');

    config()->set('engine.counts.exact_threshold', 10000);

    $this->plannedRows = 0;
    $this->exactCount = 0;

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_starts_with($sql, 'explain (format json) ') => [[
                'QUERY PLAN' => json_encode([['Plan' => ['Node Type' => 'Seq Scan', 'Plan Rows' => $this->plannedRows]]]),
            ]],
            str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => $this->exactCount]],
            default => [],
        },
    );

    $this->query = fn () => CustomRecord::query()
        ->ofType($this->objectTypeId)
        ->orderByDesc('created_at')
        ->offset(400)
        ->limit(25);

    $this->scopedSql = 'select * from "custom_records" where "custom_records"."object_type_id" = ? and "custom_records"."deleted_at" is null and "custom_records"."tenant_id" = ?';
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('asks the planner about the tenant scoped query without its order, offset and limit', function (): void {
    $this->plannedRows = 12;
    $this->exactCount = 9;

    (new RecordCountEstimator)->count(($this->query)());

    expect($this->connection->queries[0]['sql'])->toBe("explain (format json) {$this->scopedSql}")
        ->and($this->connection->queries[0]['bindings'])->toBe([$this->objectTypeId, $this->tenantId]);
});

it('counts exactly while the planner expects fewer rows than the threshold', function (): void {
    $this->plannedRows = 9999;
    $this->exactCount = 10342;

    $count = (new RecordCountEstimator)->count(($this->query)());

    expect($count)->toEqual(new RecordCountData(10342, false))
        ->and($this->connection->queries)->toHaveCount(2)
        ->and($this->connection->queries[1]['sql'])->toBe('select count(*) as "aggregate" from "custom_records" where "custom_records"."object_type_id" = ? and "custom_records"."deleted_at" is null and "custom_records"."tenant_id" = ?')
        ->and($this->connection->queries[1]['bindings'])->toBe([$this->objectTypeId, $this->tenantId]);
});

it('returns the planner estimate marked as estimated once it reaches the threshold and never counts', function (): void {
    $this->plannedRows = 1234000;

    $count = (new RecordCountEstimator)->count(($this->query)());

    expect($count)->toEqual(new RecordCountData(1234000, true))
        ->and($this->connection->queries)->toHaveCount(1);
});

it('reads the threshold from the engine configuration', function (): void {
    config()->set('engine.counts.exact_threshold', 50);
    $this->plannedRows = 50;

    $count = (new RecordCountEstimator)->count(($this->query)());

    expect($count->isEstimated)->toBeTrue()
        ->and($count->count)->toBe(50);
});

it('falls back to the exact count when the plan carries no row estimate', function (): void {
    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => str_starts_with($sql, 'explain')
            ? [['QUERY PLAN' => '[]']]
            : [['aggregate' => 7]],
    );

    $count = (new RecordCountEstimator)->count(($this->query)());

    expect($count)->toEqual(new RecordCountData(7, false));
});
