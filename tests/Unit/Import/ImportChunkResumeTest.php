<?php

declare(strict_types=1);

use App\Actions\Import\ProcessImportChunkAction;
use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\ObjectType;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\ImportRowWriter;
use App\Support\Import\PreparedImportRows;
use App\Support\Reports\ReportExecutionContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $tenant = AccessContext::tenant();
    $user = AccessContext::user($tenant, [], 'resume-user');
    $this->job = ModelStub::make(ImportJob::class, [
        'id' => ModelStub::ulid('resume-job'),
        'tenant_id' => (string) $tenant->getKey(),
        'user_id' => (string) $user->getKey(),
        'object_type_id' => ModelStub::ulid('resume-type'),
        'status' => ImportJobStatus::Running,
        'source_disk' => 'local',
        'source_path' => 'original.csv',
        'format' => 'csv',
        'mapping' => ['columns' => ['Name' => 'name']],
        'duplicate_mode' => 'insert',
        'total_rows' => 3,
        'processed_rows' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'error_count' => 0,
    ]);
    $this->jobRow = $this->job->getAttributes();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->job->object_type_id,
        'tenant_id' => $this->job->tenant_id,
    ]);
    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "import_jobs"') ? [$this->jobRow] : [$this->objectType->getAttributes()],
        function (string $sql, array $bindings): int {
            preg_match_all('/"([a-z_]+)" = \?/', $sql, $matches);

            foreach ($matches[1] as $index => $column) {
                $this->jobRow[$column] = $bindings[$index];
            }

            return 1;
        },
    );
    $context = Mockery::mock(ReportExecutionContext::class);
    $context->shouldReceive('runAsViewer')->andReturnUsing(static fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($user));
    $factory = Mockery::mock(ImportReaderFactory::class);
    $factory->shouldNotReceive('make');
    $this->writer = Mockery::mock(ImportRowWriter::class);
    $prepared = new PreparedImportRows;
    $prepared->prepare($this->job, static fn (): array => [['Name' => 'A'], ['Name' => 'B'], ['Name' => 'C']]);
    $action = new ProcessImportChunkAction($context, $factory, $this->writer, $prepared);
    $this->run = fn (int $offset = 0): mixed => $action->execute(
        $this->job->tenant_id, $this->job->user_id, $this->job->id, 'local', 'untrusted.csv', 'csv', null, $offset, 3,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('resumes at the first uncommitted row and never repeats committed inserts or counters', function (): void {
    $names = [];
    $failed = false;
    $this->writer->shouldReceive('write')->andReturnUsing(function (array $row) use (&$names, &$failed): string {
        $names[] = $row['Name'];

        if ($row['Name'] === 'B' && !$failed) {
            $failed = true;

            throw new RuntimeException('Temporary failure');
        }

        return $row['Name'] === 'C' ? 'skipped' : 'created';
    });

    expect(fn () => ($this->run)())->toThrow(RuntimeException::class, 'Temporary failure')
        ->and($this->jobRow['processed_rows'])->toBe(1)
        ->and($this->jobRow['created_count'])->toBe(1);

    ($this->run)();
    ($this->run)();

    expect($names)->toBe(['A', 'B', 'B', 'C'])
        ->and($this->jobRow['processed_rows'])->toBe(3)
        ->and($this->jobRow['created_count'])->toBe(2)
        ->and($this->connection->openedTransactions)->toBe(4)
        ->and(collect($this->connection->sqlOf('import_jobs'))->filter(fn (string $sql): bool => str_contains($sql, 'for update')))->toHaveCount(4);
});

it('rejects a gap before a chunk rather than silently skipping unprocessed records', function (): void {
    $this->writer->shouldNotReceive('write');

    expect(fn () => ($this->run)(1))->toThrow(RuntimeException::class, 'Import chunks must be processed in order.')
        ->and($this->jobRow['processed_rows'])->toBe(0);
});

it('ignores late activity retries for an already failed import', function (): void {
    $this->jobRow['status'] = ImportJobStatus::Failed->value;
    $this->writer->shouldNotReceive('write');

    ($this->run)();

    expect($this->connection->writtenStatements)->toBe([]);
});
