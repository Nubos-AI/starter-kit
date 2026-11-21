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

it('drops the run with its tenant and its object type but survives the deletion of its user', function (): void {
    expect($this->schema->hasForeignKey('record_backfill_runs', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_backfill_runs', 'object_type_id', 'object_types', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_backfill_runs', 'user_id', 'users', 'set null'))->toBeTrue();
});

it('hides the runs of another tenant behind the tenant scope of the model', function (): void {
    $shape = QueryShape::of(RecordBackfillRun::class);

    expect($shape->isScopedToTenant('record_backfill_runs', (string) $this->tenant->getKey()))->toBeTrue();
});
