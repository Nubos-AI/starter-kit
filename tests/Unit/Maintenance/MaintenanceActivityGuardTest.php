<?php

declare(strict_types=1);

use App\Activities\Engine\ProcessBulkChunkActivity;
use App\Activities\Engine\RecomputeRollupActivity;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Support\Engine\BulkChunkRunner;
use App\Support\Engine\RollupRecomputer;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Maintenance\MaintenanceRetryOptions;
use App\Workflows\Engine\BulkActionWorkflow;
use App\Workflows\Engine\RecordBackfillWorkflow;
use App\Workflows\Engine\RollupDebounceWorkflow;
use App\Workflows\Formulas\FormulaBackfillWorkflow;
use App\Workflows\Import\ImportWorkflow;
use App\Workflows\Promotion\PromotionWorkflow;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->lockedTenantId = ModelStub::ulid('maintenance-locked-tenant');
    $this->freeTenantId = ModelStub::ulid('maintenance-free-tenant');

    $this->registry = new class([]) extends MaintenanceLockRegistry
    {
        /**
         * @var list<string>
         */
        public array $askedFor = [];

        /**
         * @param  list<string>  $locked
         */
        public function __construct(private array $locked) {}

        /**
         * @param  list<string>  $locked
         */
        public function locking(array $locked): void
        {
            $this->locked = $locked;
        }

        public function assertWritable(string $tenantId, string $entryPoint, array $context = []): void
        {
            $this->askedFor[] = $entryPoint;

            if (in_array($tenantId, $this->locked, true)) {
                throw TenantUnderMaintenanceException::writeRefused($tenantId);
            }
        }
    };

    $this->registry->locking([$this->lockedTenantId]);

    $this->bulkRunner = new class extends BulkChunkRunner
    {
        /**
         * @var list<string>
         */
        public array $ran = [];

        public function __construct() {}

        public function run(string $action, string $tenantId, string $actingUserId, string $objectTypeId, array $recordIds, array $payload, string $reportKey): void
        {
            $this->ran[] = $action.'@'.$tenantId;
        }
    };

    $this->rollupRecomputer = new class extends RollupRecomputer
    {
        /**
         * @var list<string>
         */
        public array $recomputed = [];

        public function __construct() {}

        public function recompute(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys = []): void
        {
            $this->recomputed[] = 'record@'.$tenantId;
        }

        public function recomputeOwn(string $tenantId, string $objectTypeId, string $recordId): void
        {
            $this->recomputed[] = 'own@'.$tenantId;
        }
    };

    $this->bulkActivity = fn (): ProcessBulkChunkActivity => new ProcessBulkChunkActivity($this->bulkRunner, $this->registry);
    $this->rollupActivity = fn (): RecomputeRollupActivity => new RecomputeRollupActivity($this->rollupRecomputer, $this->registry);
});

it('refuses a writing bulk chunk of a locked tenant before the runner touches a record', function (): void {
    expect(fn () => ($this->bulkActivity)()->processBulkChunk(
        'set-field',
        $this->lockedTenantId,
        ModelStub::ulid('actor'),
        ModelStub::ulid('type'),
        [ModelStub::ulid('record')],
        ['status' => 'closed'],
        'bulk-report',
    ))->toThrow(TenantUnderMaintenanceException::class)
        ->and($this->bulkRunner->ran)->toBe([]);
});

it('runs the same bulk chunk for a tenant that is not locked', function (): void {
    ($this->bulkActivity)()->processBulkChunk(
        'set-field',
        $this->freeTenantId,
        ModelStub::ulid('actor'),
        ModelStub::ulid('type'),
        [ModelStub::ulid('record')],
        ['status' => 'closed'],
        'bulk-report',
    );

    expect($this->bulkRunner->ran)->toBe(['set-field@'.$this->freeTenantId]);
});

it('lets a reading csv export chunk of a locked tenant through without asking the lock registry', function (): void {
    ($this->bulkActivity)()->processBulkChunk(
        'export-csv',
        $this->lockedTenantId,
        ModelStub::ulid('actor'),
        ModelStub::ulid('type'),
        [ModelStub::ulid('record')],
        [],
        'bulk-report',
    );

    expect($this->bulkRunner->ran)->toBe(['export-csv@'.$this->lockedTenantId])
        ->and($this->registry->askedFor)->toBe([]);
});

it('refuses both rollup recomputations of a locked tenant before a rollup is written', function (): void {
    $activity = ($this->rollupActivity)();

    expect(fn () => $activity->recomputeRollup($this->lockedTenantId, ModelStub::ulid('type'), ModelStub::ulid('record'), []))
        ->toThrow(TenantUnderMaintenanceException::class)
        ->and(fn () => $activity->recomputeOwnRollups($this->lockedTenantId, ModelStub::ulid('type'), ModelStub::ulid('record')))
        ->toThrow(TenantUnderMaintenanceException::class)
        ->and($this->rollupRecomputer->recomputed)->toBe([]);
});

it('never retries a maintenance refusal', function (): void {
    expect(MaintenanceRetryOptions::make()->nonRetryableExceptions)->toBe([TenantUnderMaintenanceException::class])
        ->and(MaintenanceRetryOptions::make()->withMaximumAttempts(3)->nonRetryableExceptions)
        ->toBe([TenantUnderMaintenanceException::class]);
});

it('builds every tenant writing workflow activity with the maintenance retry options', function (string $workflow): void {
    $sources = '';

    for ($class = new ReflectionClass($workflow); $class !== false; $class = $class->getParentClass()) {
        $sources .= (string) file_get_contents((string) $class->getFileName());
    }

    expect($sources)->toContain('->withRetryOptions(MaintenanceRetryOptions::make()');
})->with([
    'bulk action' => [BulkActionWorkflow::class],
    'import' => [ImportWorkflow::class],
    'record backfill' => [RecordBackfillWorkflow::class],
    'formula backfill' => [FormulaBackfillWorkflow::class],
    'rollup debounce' => [RollupDebounceWorkflow::class],
]);

it('attempts the promotion activity exactly once so a maintenance refusal ends the run without a retry', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass(PromotionWorkflow::class))->getFileName());

    expect($source)->toContain('->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))');
});
