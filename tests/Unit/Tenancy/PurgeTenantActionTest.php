<?php

declare(strict_types=1);

use App\Actions\Tenancy\PurgeTenantAction;
use App\DTOs\Tenancy\PurgeReport;
use App\Models\Tenant;
use App\Support\Tenancy\ArtifactPurgeOrder;
use App\Support\Tenancy\TenantContentPurger;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = ModelStub::make(Tenant::class, [
        'id' => ModelStub::ulid('purged-tenant'),
        'name' => 'Purged',
        'slug' => 'purged',
    ]);

    $this->engine = new class extends NullEngine
    {
        /** @var list<list<string>> */
        public array $removedKeys = [];

        public function delete($models): void
        {
            $this->removedKeys[] = $models->map(static fn ($model): string => (string) $model->getKey())->values()->all();
        }
    };

    config(['scout.driver' => 'recording', 'scout.queue' => false]);
    $engine = $this->engine;
    app(EngineManager::class)->extend('recording', static fn (): NullEngine => $engine);

    $this->report = new PurgeReport([], 0, 0, []);

    $this->purger = Mockery::mock(TenantContentPurger::class);
    app()->instance(TenantContentPurger::class, $this->purger);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(TenantContentPurger::class);
    Mockery::close();
});

it('removes the records from search, purges the content and deletes the tenant itself', function (): void {
    $recordIds = [ModelStub::ulid('purged-record-one'), ModelStub::ulid('purged-record-two')];
    $tenantId = ModelStub::ulid('purged-tenant');

    $connection = StaticQueryConnection::install(
        static fn (string $sql, array $bindings): array => str_contains($sql, 'from "custom_records"') && !in_array($recordIds[1], $bindings, true)
            ? array_map(static fn (string $id): array => ['id' => $id, 'tenant_id' => $tenantId], $recordIds)
            : [],
        static fn (): int => 1,
    );

    $this->purger->shouldReceive('purge')
        ->once()
        ->with($tenantId, app(ArtifactPurgeOrder::class)->full())
        ->andReturn($this->report);

    $report = app(PurgeTenantAction::class)->execute($this->tenant);

    $recordQuery = array_values(array_filter($connection->queries, static fn (array $query): bool => str_contains($query['sql'], 'from "custom_records"')))[0];

    expect($report)->toBe($this->report)
        ->and($this->engine->removedKeys)->toBe([$recordIds])
        ->and($recordQuery['sql'])->toContain('"custom_records"."tenant_id" = ?')
        ->and($recordQuery['bindings'])->toContain($tenantId)
        ->and($connection->writtenSqlOf('tenants'))->toHaveCount(1)
        ->and($connection->writtenSqlOf('tenants')[0])->toStartWith('delete from "tenants"');
});

it('keeps the tenant row when its content could not be purged', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->purger->shouldReceive('purge')->once()->andThrow(new RuntimeException('purge failed'));

    expect(fn (): PurgeReport => app(PurgeTenantAction::class)->execute($this->tenant))
        ->toThrow(RuntimeException::class)
        ->and($connection->writtenSqlOf('tenants'))->toBe([]);
});
