<?php

declare(strict_types=1);

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
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'bulk-csv-actor');
    $this->objectTypeId = ModelStub::ulid('bulk-csv-object-type');
    $this->batchId = 'b2d7a2c4-1b2e-4f3a-9c8d-0a1b2c3d4e5f';

    $this->disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));
    Storage::fake($this->disk);

    new FakeFieldVisibilityResolver;

    $context = Mockery::mock(ActingUserContext::class);
    $context->shouldReceive('run')->andReturnUsing(fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($this->actor));
    app()->instance(ActingUserContext::class, $context);

    $field = fn (string $key, int $position): array => [
        'id' => ModelStub::ulid("bulk-csv-field-{$key}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => 'text_short',
        'is_encrypted' => false,
        'list_position' => $position,
    ];

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "object_types"') => [[
            'id' => $this->objectTypeId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'slug' => 'deal',
            'key' => 'deal',
        ]],
        str_contains($sql, 'from "field_definitions"') => [$field('title', 1), $field('note', 2)],
        str_contains($sql, 'from "custom_records"') => [[
            'id' => ModelStub::ulid('bulk-csv-record'),
            'tenant_id' => (string) $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'version' => 1,
            'data' => json_encode(['title' => '=HYPERLINK("https://evil.example","Klick")', 'note' => 'Harmlos']),
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

it('neutralises a spreadsheet formula in a bulk csv export the same way the regular export does', function (): void {
    app(BulkChunkRunner::class)->run(
        'export-csv',
        (string) $this->tenant->getKey(),
        (string) $this->actor->getKey(),
        $this->objectTypeId,
        [ModelStub::ulid('bulk-csv-record')],
        [],
        $this->batchId,
    );

    $path = BulkBatchReport::downloadPath($this->batchId);
    $csv = Storage::disk($this->disk)->get((string) $path);

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain(',"=HYPERLINK')
        ->and($csv)->toContain('Harmlos');
});
