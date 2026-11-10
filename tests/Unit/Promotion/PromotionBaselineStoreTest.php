<?php

declare(strict_types=1);

use App\Models\PromotionBaseline;
use App\Models\PromotionRun;
use App\Support\Promotion\PromotionBaselineStore;
use Illuminate\Support\Carbon;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('promotion-baseline-tenant');
    $this->store = new PromotionBaselineStore;
    $this->counterpart = 'sandbox:release';
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000068_create_promotion_baselines_table.php');
    $this->runs = SchemaShape::ofMigration('database/migrations/0001_01_01_000070_create_promotion_runs_table.php');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads the baseline of one counterpart inside the bound tenant, oldest entry first', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): array => $this->store->hashesFor($this->counterpart));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('promotion_baselines'))->toBeTrue()
        ->and($attempt?->hasBinding($this->counterpart))->toBeTrue()
        ->and($attempt?->isScopedToTenant('promotion_baselines', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('order by "id" asc');
});

it('blocks every baseline row when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(PromotionBaseline::class)->blocksEveryRow())->toBeTrue();
});

it('prunes and rewrites a whole baseline inside one transaction of the bound tenant', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        $this->store->remember($this->counterpart, ['object-types:invoice' => 'hash']);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $prune = $connection->writtenStatements[0] ?? null;
    $shape = new QueryShape((string) ($prune['sql'] ?? ''), $prune['bindings'] ?? []);

    expect($connection->openedTransactions)->toBe(1)
        ->and($shape->sql)->toStartWith('delete from "promotion_baselines"')
        ->and($shape->sql)->toContain('"artifact_key" not in (?)')
        ->and($shape->hasBinding($this->counterpart))->toBeTrue()
        ->and($shape->hasBinding('object-types:invoice'))->toBeTrue()
        ->and($shape->isScopedToTenant('promotion_baselines', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($connection->writtenSqlOf('promotion_baselines'))->toHaveCount(2)
        ->and($connection->writtenStatements[1]['sql'])->toStartWith('insert into "promotion_baselines"');
});

it('deletes exactly one artifact of one counterpart when it forgets one', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => $this->store->forgetOne($this->counterpart, 'object-types:invoice'));

    expect($attempt?->sql)->toStartWith('delete from "promotion_baselines"')
        ->and($attempt?->hasBinding($this->counterpart))->toBeTrue()
        ->and($attempt?->hasBinding('object-types:invoice'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('promotion_baselines', (string) $this->tenant->getKey()))->toBeTrue();
});

it('deletes the whole baseline of one counterpart and never that of another', function (): void {
    $attempt = QueryShape::attemptedBy(fn () => $this->store->forget($this->counterpart));

    expect($attempt?->sql)->toStartWith('delete from "promotion_baselines"')
        ->and($attempt?->hasBinding($this->counterpart))->toBeTrue()
        ->and($attempt?->hasBinding('object-types:invoice'))->toBeFalse()
        ->and($attempt?->isScopedToTenant('promotion_baselines', (string) $this->tenant->getKey()))->toBeTrue();
});

it('stamps a remembered artifact with its sync moment', function (): void {
    Carbon::setTestNow('2026-10-31 09:15:00');

    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        $this->store->rememberOne($this->counterpart, 'object-types:invoice', 'hash');
    } finally {
        StaticQueryConnection::uninstall();
        Carbon::setTestNow();
    }

    preg_match('/\(([^)]*)\) values/', $connection->writtenStatements[0]['sql'], $columns);
    $row = array_combine(
        array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $columns[1])),
        $connection->writtenStatements[0]['bindings'],
    );

    expect($row['synced_at'])->toStartWith('2026-10-31 09:15:00')
        ->and($row['hash'])->toBe('hash')
        ->and($row['tenant_id'])->toBe((string) $this->tenant->getKey());
});

it('lets one tenant hold one hash per counterpart and artifact', function (): void {
    expect($this->schema->columnsOf('promotion_baselines'))->toBe([
        'id',
        'tenant_id',
        'counterpart_key',
        'artifact_key',
        'hash',
        'synced_at',
        'created_at',
        'updated_at',
    ])
        ->and($this->schema->has('add constraint "unq_promotion_baselines_entry" unique ("tenant_id", "counterpart_key", "artifact_key")'))->toBeTrue()
        ->and($this->schema->has('create index "idx_promotion_baselines_counterpart" on "promotion_baselines" ("tenant_id", "counterpart_key")'))->toBeTrue()
        ->and($this->schema->hasForeignKey('promotion_baselines', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('leaves the source tenant of a run without a foreign key so a removed sandbox cannot drop the run', function (): void {
    expect($this->runs->createStatementFor('promotion_runs'))->toContain('"source_tenant_id" char(26) null')
        ->and($this->runs->has('add constraint "promotion_runs_source_tenant_id_foreign"'))->toBeFalse()
        ->and($this->runs->hasForeignKey('promotion_runs', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('creates the promotion run columns in the order the conventions demand', function (): void {
    expect($this->runs->columnsOf('promotion_runs'))->toBe([
        'id',
        'tenant_id',
        'source_tenant_id',
        'triggered_by_id',
        'snapshot_group_id',
        'counterpart_key',
        'direction',
        'status',
        'selection',
        'conflict_decisions',
        'report',
        'started_at',
        'finished_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('hides a run of another tenant behind the tenant scope', function (): void {
    expect(QueryShape::of(PromotionRun::class)->isScopedToTenant('promotion_runs', (string) $this->tenant->getKey()))->toBeTrue()
        ->and(QueryShape::of(PromotionRun::withoutTenantScope())->isScopedToTenant('promotion_runs', (string) $this->tenant->getKey()))->toBeFalse();
});

it('drops both promotion tables again on the way down', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000068_create_promotion_baselines_table.php', 'down')
        ->has('drop table if exists "promotion_baselines"'))->toBeTrue()
        ->and(SchemaShape::ofMigration('database/migrations/0001_01_01_000070_create_promotion_runs_table.php', 'down')
            ->has('drop table if exists "promotion_runs"'))->toBeTrue();
});
