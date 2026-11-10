<?php

declare(strict_types=1);

use App\Exceptions\Engine\BulkExportWithoutRowsException;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\BulkChunkRunner;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('bulk-empty-tenant');
    $this->actor = AccessContext::user($this->tenant, [], 'bulk-empty-actor');
    $this->objectTypeId = ModelStub::ulid('bulk-empty-object-type');
    $this->batchId = 'c3d7a2c4-1b2e-4f3a-9c8d-0a1b2c3d4e5f';

    Storage::fake((string) config('engine.bulk.export_disk', config('filesystems.default')));

    new FakeFieldVisibilityResolver;

    $context = Mockery::mock(ActingUserContext::class);
    $context->shouldReceive('run')->andReturnUsing(fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($this->actor));
    app()->instance(ActingUserContext::class, $context);

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "object_types"') => [[
            'id' => $this->objectTypeId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'slug' => 'deal',
            'key' => 'deal',
        ]],
        default => [],
    });

    BulkBatchReport::initProgress($this->batchId, (string) $this->tenant->getKey(), (string) $this->actor->getKey(), 1);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a bulk csv export without a visible row with a typed failure the workflow does not retry', function (): void {
    expect(fn () => app(BulkChunkRunner::class)->run(
        'export-csv',
        (string) $this->tenant->getKey(),
        (string) $this->actor->getKey(),
        $this->objectTypeId,
        [ModelStub::ulid('bulk-empty-record')],
        [],
        $this->batchId,
    ))->toThrow(BulkExportWithoutRowsException::class, __('i18n.backend.support.engine.bulk_chunk_runner.bulk_export_produced_no_visible_rows'));

    expect(config('temporal.activity_limits.non_retryable'))->toContain(BulkExportWithoutRowsException::class);
});
