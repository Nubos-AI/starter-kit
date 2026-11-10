<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Support\Engine\IndexRegistry;
use App\Support\Tenancy\TenantContentPurger;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('purged-tenant');
    $this->objectTypeIds = [ModelStub::ulid('purged-companies'), ModelStub::ulid('purged-contacts')];

    $this->indexes = Mockery::mock(IndexRegistry::class);
    app()->instance(IndexRegistry::class, $this->indexes);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(IndexRegistry::class);
    Mockery::close();
});

it('drops the record indexes of every object type the purged tenant owned', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => str_contains($sql, 'from "object_types"') && $bindings === [$this->tenantId]
            ? array_map(static fn (string $id): array => ['id' => $id], $this->objectTypeIds)
            : [],
    );

    foreach ($this->objectTypeIds as $objectTypeId) {
        $this->indexes->shouldReceive('dropObjectTypeIndexes')->once()->with($objectTypeId);
    }

    app(TenantContentPurger::class)->purge($this->tenantId, []);

    $objectTypeQuery = $connection->sqlOf('object_types')[0];

    expect($objectTypeQuery)->toContain('"tenant_id" = ?');
});

it('drops no record index when the tenant owned no object type', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);

    $this->indexes->shouldNotReceive('dropObjectTypeIndexes');

    app(TenantContentPurger::class)->purge($this->tenantId, []);

    expect($connection->sqlOf('object_types'))->toHaveCount(1);
});

it('removes the export, import and config import files of the purged tenant and spares other tenants', function (): void {
    config(['engine.bulk.export_disk' => 'purge-exports', 'filesystems.default' => 'purge-uploads']);
    $exports = Storage::fake('purge-exports');
    $uploads = Storage::fake('purge-uploads');
    $local = Storage::fake('local');
    $otherTenantId = ModelStub::ulid('surviving-tenant');

    $exports->put("exports/{$this->tenantId}/export-job.csv", 'a');
    $exports->put("exports/{$otherTenantId}/export-job.csv", 'a');
    $uploads->put("imports/{$this->tenantId}/uploader/source.csv", 'a');
    $uploads->put("imports/{$this->tenantId}/errors/import-job.csv", 'a');
    $local->put("config-imports/{$this->tenantId}/promotion-run/bundle.json", 'a');

    StaticQueryConnection::install(static fn (): array => []);
    $this->indexes->shouldNotReceive('dropObjectTypeIndexes');

    $report = app(TenantContentPurger::class)->purge($this->tenantId, []);

    expect($exports->directoryExists("exports/{$this->tenantId}"))->toBeFalse()
        ->and($uploads->directoryExists("imports/{$this->tenantId}"))->toBeFalse()
        ->and($local->directoryExists("config-imports/{$this->tenantId}"))->toBeFalse()
        ->and($exports->exists("exports/{$otherTenantId}/export-job.csv"))->toBeTrue()
        ->and($report->deletedFiles)->toBe(4)
        ->and($report->failedFiles)->toBe(0);
});

it('aborts the purge before deleting any row when a tenant export directory cannot be removed', function (): void {
    config(['engine.bulk.export_disk' => 'purge-exports', 'filesystems.default' => 'purge-uploads']);
    Storage::fake('purge-uploads');
    Storage::fake('local');

    $failingDisk = Mockery::mock(FilesystemAdapter::class);
    $failingDisk->shouldReceive('directoryExists')->andReturn(true);
    $failingDisk->shouldReceive('allFiles')->andReturn(["exports/{$this->tenantId}/export-job.csv"]);
    $failingDisk->shouldReceive('deleteDirectory')->once()->with("exports/{$this->tenantId}")->andReturn(false);
    Storage::set('purge-exports', $failingDisk);

    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 0);
    $this->indexes->shouldNotReceive('dropObjectTypeIndexes');

    expect(fn () => app(TenantContentPurger::class)->purge($this->tenantId, []))
        ->toThrow(TenantPurgeFailedException::class)
        ->and($connection->writtenStatements)->toBe([]);
});
