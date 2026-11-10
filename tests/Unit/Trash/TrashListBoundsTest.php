<?php

declare(strict_types=1);

use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\StorageStrategy;
use App\Http\Controllers\Engine\TrashController;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    AccessContext::grant('orders.view', 'orders.delete', 'archived.view');

    $this->objectTypeRow = fn (string $slug): array => [
        'id' => ModelStub::ulid($slug),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => $slug,
        'key' => $slug,
        'name' => ucfirst($slug),
        'is_system' => false,
        'storage_strategy' => StorageStrategy::Generic->value,
    ];

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'count(*)') => [['aggregate' => 60]],
        str_contains($sql, 'from "custom_records"') => [[
            'id' => ModelStub::ulid('trashed-order'),
            'tenant_id' => (string) $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('orders'),
            'record_number' => 'ORD-1',
            'data' => '{}',
            'deleted_at' => '2026-10-01 10:00:00',
        ]],
        str_contains($sql, 'from "object_types"') => [
            ($this->objectTypeRow)('orders'),
            ($this->objectTypeRow)('salaries'),
            ($this->objectTypeRow)('archived'),
        ],
        default => [],
    });

    $capabilities = Mockery::mock(ObjectTypeCapabilityGuard::class);
    $capabilities->shouldReceive('supports')
        ->andReturnUsing(static fn (ObjectType $type, ObjectTypeCapability $capability): bool => $capability === ObjectTypeCapability::Trash
            && $type->slug !== 'archived');
    app()->instance(ObjectTypeCapabilityGuard::class, $capabilities);

    $titles = Mockery::mock(RecordTitleResolver::class);
    $titles->shouldReceive('titleFor')->andReturn('A record');
    app()->instance(RecordTitleResolver::class, $titles);

    $this->props = function (array $query = []): array {
        $request = Request::create('/trash', 'GET', $query);
        $request->setUserResolver(fn () => $this->actor);
        $request->headers->set('X-Inertia', 'true');
        app()->instance('request', $request);

        return app(TrashController::class)->index($request)->toResponse($request)->getData(true)['props'];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('reads one page of trashed records restricted to the object types the actor may view and that keep a trash', function (): void {
    $props = ($this->props)(['page' => 2]);

    $recordQueries = array_values(array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "custom_records"')
            && !str_contains($query['sql'], 'count(*)'),
    ));

    expect($recordQueries)->toHaveCount(1)
        ->and($recordQueries[0]['sql'])->toContain('"object_type_id" in (?)')
        ->and($recordQueries[0]['sql'])->toContain('limit 25 offset 25')
        ->and($recordQueries[0]['bindings'])->toContain(ModelStub::ulid('orders'))
        ->and($recordQueries[0]['bindings'])->not->toContain(ModelStub::ulid('salaries'))
        ->and($recordQueries[0]['bindings'])->not->toContain(ModelStub::ulid('archived'))
        ->and(array_column($props['records'], 'id'))->toBe([ModelStub::ulid('trashed-order')])
        ->and($props['pagination'])->toBe(['currentPage' => 2, 'lastPage' => 3, 'perPage' => 25, 'total' => 60]);
});

it('asks the database nothing per row once the page is loaded', function (): void {
    ($this->props)();

    $recordQueries = array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "custom_records"'),
    );

    expect($recordQueries)->toHaveCount(2);
});

it('lets no trashed record through when the actor may view no object type that keeps a trash', function (): void {
    AccessContext::grant('salaries.delete');

    ($this->props)();

    $recordQueries = array_values(array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "custom_records"'),
    ));

    expect($recordQueries)->not->toBeEmpty();

    foreach ($recordQueries as $query) {
        expect($query['sql'])->toContain('0 = 1');
    }
});
