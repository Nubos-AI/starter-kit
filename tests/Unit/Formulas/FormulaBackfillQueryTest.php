<?php

declare(strict_types=1);

use App\Actions\Formulas\CancelFormulaBackfillAction;
use App\DTOs\Formulas\FormulaBackfillData;
use App\Enums\Formulas\BackfillStatus;
use App\Http\Controllers\Formulas\FormulaBackfillsController;
use App\Models\ObjectType;
use App\Support\Formulas\FormulaBackfillBatchRunner;
use App\Support\Formulas\FormulaBackfillProgress;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('backfill-tenant');
    $this->foreignTenantId = ModelStub::ulid('backfill-foreign-tenant');
    $this->objectTypeId = ModelStub::ulid('backfill-object-type');
    $this->fieldDefinitionId = ModelStub::ulid('backfill-field');
    $this->runId = ModelStub::ulid('backfill-run');

    /** @var callable(?string, int):FormulaBackfillData */
    $this->input = fn (?string $cursorId = null, int $batchSize = 200): FormulaBackfillData => new FormulaBackfillData(
        tenantId: $this->tenantId,
        fieldDefinitionId: $this->fieldDefinitionId,
        objectTypeId: $this->objectTypeId,
        backfillRunId: $this->runId,
        batchSize: $batchSize,
        cursorId: $cursorId,
    );

    /** @var callable(?string, int):QueryShape */
    $this->batchShape = fn (?string $cursorId = null, int $batchSize = 200): QueryShape => QueryShape::of(
        app(FormulaBackfillBatchRunner::class)->batchQuery(($this->input)($cursorId, $batchSize)),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

test('the batch query reads the records of the tenant it was handed and of no other', function (): void {
    $shape = ($this->batchShape)();

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeFalse()
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue();
});

test('the batch query keeps its own tenant even while another tenant is the ambient one', function (): void {
    AccessContext::tenant('backfill-ambient-tenant');

    $shape = ($this->batchShape)();

    expect($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and(substr_count($shape->sql, '"tenant_id" ='))->toBe(1);
});

test('the batch query reaches every record of the object type regardless of who may see it', function (): void {
    $tenant = AccessContext::tenant('backfill-ambient-tenant');
    AccessContext::team($tenant, 'backfill-ambient-team');
    AccessContext::enforceRowAccess();

    $shape = ($this->batchShape)();

    expect($shape->sql)->not->toContain('"team_id"')
        ->and($shape->sql)->not->toContain('"owner_id"')
        ->and($shape->blocksEveryRow())->toBeFalse();

    AccessContext::suspendRowAccess();
});

test('the batch query skips soft deleted records', function (): void {
    expect(($this->batchShape)()->sql)->toContain('"deleted_at" is null');
});

test('the batch query pages by an ordered primary-key keyset and never by an offset', function (): void {
    $cursorId = ModelStub::ulid('backfill-cursor');

    $shape = ($this->batchShape)($cursorId, 3);

    expect($shape->sql)->toContain('order by "id" asc')
        ->and($shape->sql)->toContain('"id" >')
        ->and($shape->sql)->toContain('limit 3')
        ->and($shape->sql)->not->toContain('offset')
        ->and($shape->sql)->not->toContain('created_at')
        ->and($shape->hasBinding($cursorId))->toBeTrue();
});

test('the first batch starts without a keyset predicate', function (): void {
    expect(($this->batchShape)()->sql)->not->toContain('"id" >');
});

test('cancelling the open runs of a field touches only that field inside its own tenant and only open runs', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => app(FormulaBackfillProgress::class)
        ->cancelOpenRuns($this->tenantId, $this->fieldDefinitionId));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('update "formula_backfill_runs"')
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->fieldDefinitionId))->toBeTrue()
        ->and($shape->hasBinding(BackfillStatus::Cancelled->value))->toBeTrue();

    foreach (BackfillStatus::openValues() as $openValue) {
        expect($shape->hasBinding($openValue))->toBeTrue();
    }

    foreach (BackfillStatus::cases() as $status) {
        if ($status->isOpen() || $status === BackfillStatus::Cancelled) {
            continue;
        }

        expect($shape->hasBinding($status->value))->toBeFalse();
    }
});

test('the status query reads the newest run of the object type inside the acting tenant only', function (): void {
    $tenant = AccessContext::tenant('backfill-status-tenant');

    $objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $tenant->getKey(),
        'slug' => 'invoices',
    ]);

    $controller = new FormulaBackfillsController(Mockery::spy(CancelFormulaBackfillAction::class));

    $shape = QueryShape::attemptedBy(fn (): mixed => $controller->show($objectType));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('formula_backfill_runs'))->toBeTrue()
        ->and($shape->isScopedToTenant('formula_backfill_runs', (string) $tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->sql)->toContain('order by "created_at" desc, "id" desc')
        ->and($shape->sql)->toContain('limit 1');
});
