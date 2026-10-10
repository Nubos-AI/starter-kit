<?php

declare(strict_types=1);

use App\Enums\Import\ImportJobStatus;
use App\Models\User;
use App\Notifications\ImportCompletedNotification;
use App\Support\Import\ImportErrorReport;
use App\Support\Import\ImportFinalizer;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
    Storage::fake('imports');

    $this->tenantId = ModelStub::ulid('finalizer-tenant');
    $this->userId = ModelStub::ulid('finalizer-user');
    $this->importJobId = ModelStub::ulid('finalizer-import-job');
    $this->reportPath = "imports/{$this->tenantId}/errors/{$this->importJobId}.csv";

    $this->tenantRow = ['id' => $this->tenantId, 'name' => 'Nubos'];
    $this->userRow = ['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'importer@example.test'];
    $this->jobRow = [
        'id' => $this->importJobId,
        'tenant_id' => $this->tenantId,
        'user_id' => $this->userId,
        'status' => ImportJobStatus::Running->value,
        'source_disk' => 'imports',
    ];

    /** @var callable(bool):StaticQueryConnection */
    $this->connectionWithJob = fn (bool $hasJob): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [$this->tenantRow],
            str_contains($sql, 'from "users"') => [$this->userRow],
            str_contains($sql, 'from "import_jobs"') => $hasJob ? [$this->jobRow] : [],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->finalizer = new ImportFinalizer(new ActingUserContext);

    /** @var callable():void */
    $this->finalize = fn (): mixed => $this->finalizer->finalize($this->tenantId, $this->userId, $this->importJobId);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('writes nothing and notifies nobody when the import job vanished before the finalisation', function (): void {
    $connection = ($this->connectionWithJob)(false);

    ($this->finalize)();

    expect($connection->writtenStatements)->toBe([]);
    Notification::assertNothingSent();
});

it('stores the collected row errors as a report and hands its path to the completed job', function (): void {
    $connection = ($this->connectionWithJob)(true);
    ImportErrorReport::add($this->importJobId, 4, 'Die Zeile passt nicht.', 'EXT-1');

    ($this->finalize)();

    expect($connection->writtenSqlOf('import_jobs'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['bindings'])->toContain(ImportJobStatus::Completed->value)
        ->and($connection->writtenStatements[0]['bindings'])->toContain($this->reportPath)
        ->and(Storage::disk('imports')->exists($this->reportPath))->toBeTrue()
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('leaves the report path empty and writes no file when no row failed', function (): void {
    $connection = ($this->connectionWithJob)(true);

    ($this->finalize)();

    expect($connection->writtenStatements[0]['bindings'])->toContain(null)
        ->and($connection->writtenStatements[0]['bindings'])->not->toContain($this->reportPath)
        ->and(Storage::disk('imports')->allFiles())->toBe([]);
});

it('tells the user the import job belongs to that the run is finished', function (): void {
    ($this->connectionWithJob)(true);

    ($this->finalize)();

    Notification::assertCount(1);
    Notification::assertSentTo(
        ModelStub::make(User::class, $this->userRow),
        ImportCompletedNotification::class,
    );
});

it('refuses to finalise for a tenant that no longer exists and never reads the import job', function (): void {
    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    expect(fn (): mixed => ($this->finalize)())->toThrow(AuthorizationException::class)
        ->and($connection->sqlOf('import_jobs'))->toBe([])
        ->and($connection->writtenStatements)->toBe([]);

    Notification::assertNothingSent();
});

it('keeps the import job untouched when the acting user is gone', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "tenants"') ? [$this->tenantRow] : [],
        static fn (): int => 1,
    );

    expect(fn (): mixed => ($this->finalize)())->toThrow(AuthorizationException::class)
        ->and($connection->sqlOf('import_jobs'))->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
});
