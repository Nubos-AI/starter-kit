<?php

declare(strict_types=1);

use App\Models\ImportJob;
use App\Support\Import\ImportChunkProcessor;
use App\Support\Import\ImportFinalizer;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\ImportRowWriter;
use App\Support\Import\SynchronousImportDispatcher;
use App\Support\Reports\ReportExecutionContext;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->owner = AccessContext::user($this->tenant, [], 'job-owner');

    $this->importJob = ModelStub::make(ImportJob::class, [
        'id' => ModelStub::ulid('import-job'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
        'user_id' => (string) $this->owner->getKey(),
        'source_disk' => 'local',
        'source_path' => "imports/{$this->tenantId}/{$this->owner->getKey()}/sheet.csv",
        'format' => 'csv',
        'total_rows' => 5,
    ]);

    $this->context = Mockery::mock(ReportExecutionContext::class);
    $this->readerFactory = Mockery::mock(ImportReaderFactory::class);
    $this->writer = Mockery::mock(ImportRowWriter::class);

    $this->processor = new ImportChunkProcessor($this->context, $this->readerFactory, $this->writer);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('takes disk, path, tenant and acting user of an import run from the stored job, never from the caller', function (): void {
    $processor = Mockery::mock(ImportChunkProcessor::class);
    $finalizer = Mockery::mock(ImportFinalizer::class);

    $seen = [];

    $processor->shouldReceive('process')->times(3)->andReturnUsing(
        function (string $tenantId, string $actingUserId, string $importJobId, string $disk, string $path, string $format, ?string $sheet, int $offset, int $limit) use (&$seen): void {
            $seen[] = compact('tenantId', 'actingUserId', 'importJobId', 'disk', 'path', 'format', 'offset', 'limit');
        },
    );
    $finalizer->shouldReceive('finalize')->once()->with($this->tenantId, (string) $this->owner->getKey(), (string) $this->importJob->getKey());

    (new SynchronousImportDispatcher($processor, $finalizer))->start($this->importJob, null, 2);

    expect($seen[0]['tenantId'])->toBe($this->tenantId)
        ->and($seen[0]['actingUserId'])->toBe((string) $this->owner->getKey())
        ->and($seen[0]['disk'])->toBe('local')
        ->and($seen[0]['path'])->toBe("imports/{$this->tenantId}/{$this->owner->getKey()}/sheet.csv")
        ->and(array_column($seen, 'offset'))->toBe([0, 2, 4]);
});

it('processes an import chunk in the viewer context of the user who started the run', function (): void {
    $this->context->shouldReceive('runAsViewer')
        ->once()
        ->with($this->tenantId, (string) $this->owner->getKey(), Mockery::type(Closure::class))
        ->andReturnNull();
    $this->context->shouldNotReceive('runAsDefiner');

    $this->processor->process(
        $this->tenantId,
        (string) $this->owner->getKey(),
        (string) $this->importJob->getKey(),
        'local',
        'imports/x/y/sheet.csv',
        'csv',
        null,
        0,
        50,
    );
});

it('loads the import job by its key inside that viewer context before it opens the file', function (): void {
    $this->context->shouldReceive('runAsViewer')->andReturnUsing(
        fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($this->owner),
    );
    $this->readerFactory->shouldNotReceive('make');

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->processor->process(
        $this->tenantId,
        (string) $this->owner->getKey(),
        (string) $this->importJob->getKey(),
        'local',
        'imports/x/y/sheet.csv',
        'csv',
        null,
        0,
        50,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('import_jobs'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->importJob->getKey()))->toBeTrue();
});
