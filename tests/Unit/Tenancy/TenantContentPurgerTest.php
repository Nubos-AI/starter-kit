<?php

declare(strict_types=1);

use App\Support\Engine\IndexRegistry;
use App\Support\Tenancy\TenantContentPurger;
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
