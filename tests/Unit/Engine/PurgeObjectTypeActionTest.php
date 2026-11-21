<?php

declare(strict_types=1);

use App\Actions\Engine\PurgeObjectTypeAction;
use App\Models\ObjectType;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Engine\IndexRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('purged-object-type'),
        'tenant_id' => ModelStub::ulid('purging-tenant'),
        'key' => 'projects',
        'slug' => 'projects',
        'is_system' => false,
        'deleted_at' => now()->subDay(),
    ]);

    $permissions = Mockery::mock(PermissionCatalog::class);
    $permissions->shouldReceive('namesForObjectType')->andReturn(['projects.view']);
    app()->instance(PermissionCatalog::class, $permissions);

    $this->indexes = Mockery::mock(IndexRegistry::class);
    app()->instance(IndexRegistry::class, $this->indexes);

    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance(PermissionCatalog::class);
    app()->forgetInstance(IndexRegistry::class);
    Mockery::close();
});

it('drops the record indexes of the purged object type only once the purge is committed', function (): void {
    $dropped = [];
    $this->indexes->shouldReceive('dropObjectTypeIndexes')
        ->andReturnUsing(static function (string $objectTypeId) use (&$dropped): void {
            $dropped[] = $objectTypeId;
        });

    ObjectType::withoutEvents(fn (): mixed => app(PurgeObjectTypeAction::class)->execute($this->objectType));

    expect($this->connection->writtenSqlOf('object_types'))->toHaveCount(1)
        ->and($dropped)->toBe([]);

    foreach ($this->connection->deferredAfterCommit as $callback) {
        $callback();
    }

    expect($dropped)->toBe([ModelStub::ulid('purged-object-type')]);
});

it('leaves every index alone for an object type that is not in the trash', function (): void {
    $this->objectType->deleted_at = null;

    $this->indexes->shouldNotReceive('dropObjectTypeIndexes');

    expect(fn (): mixed => app(PurgeObjectTypeAction::class)->execute($this->objectType))
        ->toThrow(InvalidArgumentException::class)
        ->and($this->connection->writtenStatements)->toBe([]);
});
