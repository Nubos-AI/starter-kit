<?php

declare(strict_types=1);

use App\Enums\Engine\StorageStrategy;
use App\Http\Controllers\Engine\TrashController;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    $this->recordRow = fn (string $seed, string $objectTypeSeed): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid($objectTypeSeed),
        'record_number' => $seed,
        'data' => '{}',
        'deleted_at' => '2026-10-01 10:00:00',
    ];

    StaticQueryConnection::install(function (string $sql, array $bindings): array {
        if (str_contains($sql, 'count(*)')) {
            return [['aggregate' => 1]];
        }

        if (str_contains($sql, 'from "custom_records"')) {
            return array_values(array_filter(
                [($this->recordRow)('kept-record', 'orders'), ($this->recordRow)('orphaned-record', 'vanished-type')],
                static fn (array $row): bool => in_array($row['object_type_id'], $bindings, true),
            ));
        }

        if (str_contains($sql, 'from "object_types"')) {
            return [[
                'id' => ModelStub::ulid('orders'),
                'tenant_id' => (string) $this->tenant->getKey(),
                'slug' => 'orders',
                'key' => 'orders',
                'name' => 'Orders',
                'is_system' => false,
                'storage_strategy' => StorageStrategy::Generic->value,
            ]];
        }

        return [];
    });

    GateSpy::allowing('view', 'restore', 'forceDelete');
    AccessContext::grant('orders.view');

    $titles = Mockery::mock(RecordTitleResolver::class);
    $titles->shouldReceive('titleFor')->andReturn('A record');
    app()->instance(RecordTitleResolver::class, $titles);

    $this->request = function (): Request {
        $request = Request::create('/trash');
        $request->setUserResolver(fn () => $this->actor);
        $request->headers->set('X-Inertia', 'true');

        return $request;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('leaves a trashed record whose object type is gone out of the trash instead of failing it', function (): void {
    $request = ($this->request)();

    $records = app(TrashController::class)->index($request)->toResponse($request)->getData(true)['props']['records'];

    expect(array_column($records, 'id'))->toBe([ModelStub::ulid('kept-record')]);
});

it('answers not found when a trashed record without object type is restored', function (): void {
    StaticQueryConnection::uninstall();
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "custom_records"')
        ? [($this->recordRow)('orphaned-record', 'vanished-type')]
        : []);

    app(TrashController::class)->restore(($this->request)(), ModelStub::ulid('orphaned-record'));
})->throws(NotFoundHttpException::class);
