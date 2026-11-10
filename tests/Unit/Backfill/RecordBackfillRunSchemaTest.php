<?php

declare(strict_types=1);

use App\Enums\Formulas\BackfillStatus;
use App\Models\RecordBackfillRun;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000055_create_record_backfill_runs_table.php');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('admits only one open run per tenant, kind and object type', function (): void {
    expect($this->schema->has(
        'CREATE UNIQUE INDEX unq_record_backfill_runs_active ON record_backfill_runs '
        ."(tenant_id, kind, object_type_id) WHERE status IN ('pending', 'running')",
    ))->toBeTrue();
});

it('guards the active run index against a drift between its column list and the open statuses', function (): void {
    $definition = (string) $this->schema->statementMatching('unq_record_backfill_runs_active');

    preg_match('/\(([^()]*)\) WHERE/', $definition, $columnMatches);
    preg_match_all("/'([^']*)'/", $definition, $literalMatches);

    $guarded = array_values(array_unique($literalMatches[1]));
    $open = BackfillStatus::openValues();

    sort($guarded);
    sort($open);

    expect(trim($columnMatches[1] ?? ''))->toBe('tenant_id, kind, object_type_id')
        ->and($guarded)->toBe($open)
        ->and($open)->toBe(['pending', 'running']);
});

it('keeps the column order of the run table at id, foreign keys, counters and timestamps', function (): void {
    expect($this->schema->columnsOf('record_backfill_runs'))->toBe([
        'id',
        'tenant_id',
        'object_type_id',
        'user_id',
        'kind',
        'status',
        'total_count',
        'processed_count',
        'error_count',
        'cursor_id',
        'finished_at',
        'created_at',
        'updated_at',
    ]);
});

it('starts every run as pending and counts from zero', function (): void {
    expect($this->schema->columnDefinition('record_backfill_runs', 'status'))
        ->toContain("default 'pending'")
        ->and($this->schema->columnDefinition('record_backfill_runs', 'total_count'))->toContain('default \'0\'')
        ->and($this->schema->columnDefinition('record_backfill_runs', 'processed_count'))->toContain('default \'0\'')
        ->and($this->schema->columnDefinition('record_backfill_runs', 'error_count'))->toContain('default \'0\'');
});

it('drops the run with its tenant and its object type but survives the deletion of its user', function (): void {
    expect($this->schema->hasForeignKey('record_backfill_runs', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_backfill_runs', 'object_type_id', 'object_types', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_backfill_runs', 'user_id', 'users', 'set null'))->toBeTrue();
});

it('keeps the down direction reversible by dropping the whole table', function (): void {
    $down = SchemaShape::ofMigration(
        'database/migrations/0001_01_01_000055_create_record_backfill_runs_table.php',
        'down',
    );

    expect($down->statements)->toBe(['drop table if exists "record_backfill_runs"']);
});

it('hides the runs of another tenant behind the tenant scope of the model', function (): void {
    $shape = QueryShape::of(RecordBackfillRun::class);

    expect($shape->isScopedToTenant('record_backfill_runs', (string) $this->tenant->getKey()))->toBeTrue();
});
