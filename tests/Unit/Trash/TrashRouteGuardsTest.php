<?php

declare(strict_types=1);

use App\Http\Controllers\Engine\TrashController;
use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Http\Middleware\Authorization\EnsurePermission;
use App\Http\Middleware\EnforceMaintenanceLock;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->routes = [
        'engine.trash.index' => 'index',
        'engine.trash.restore' => 'restore',
        'engine.trash.bulkDestroy' => 'bulkDestroy',
        'engine.trash.destroy' => 'destroy',
    ];
});

it('sends every trash route to the trash controller', function (): void {
    foreach ($this->routes as $name => $method) {
        expect(RouteShape::named($name)->handledBy())->toBe(TrashController::class.'@'.$method);
    }
});

it('locks every trash route behind authentication and the maintenance lock', function (): void {
    foreach (array_keys($this->routes) as $name) {
        $middleware = RouteShape::named($name)->resolvedMiddleware();

        expect($middleware)->toContain(Authenticate::class)
            ->and($middleware)->toContain(EnforceMaintenanceLock::class);
    }
});

it('compiles the row access rules before any record is bound to the request', function (): void {
    foreach (array_keys($this->routes) as $name) {
        expect(RouteShape::named($name)->runsBefore(EnforceRecordAccessRules::class, SubstituteBindings::class))->toBeTrue();
    }
});

it('never grants trash access through a blanket permission middleware, because the controller asks the policy per record', function (): void {
    foreach (array_keys($this->routes) as $name) {
        $middleware = RouteShape::named($name)->resolvedMiddleware();

        expect(array_values(array_filter(
            $middleware,
            static fn (string $entry): bool => str_starts_with($entry, EnsurePermission::class),
        )))->toBe([]);
    }
});

it('accepts a record identifier only in the ulid shape the engine issues', function (): void {
    foreach (['engine.trash.restore', 'engine.trash.destroy'] as $name) {
        $constraints = RouteShape::named($name)->constraints();

        expect($constraints)->toHaveKey('id');

        $pattern = '/^'.$constraints['id'].'$/';

        expect(preg_match($pattern, ModelStub::ulid('trashed-record')))->toBe(1)
            ->and(preg_match($pattern, 'not-a-ulid'))->toBe(0)
            ->and(preg_match($pattern, '1 OR 1=1'))->toBe(0);
    }
});

it('separates the single purge from the bulk purge by verb and path', function (): void {
    expect(RouteShape::named('engine.trash.destroy')->methods())->toContain('DELETE')
        ->and(RouteShape::named('engine.trash.bulkDestroy')->methods())->toContain('POST')
        ->and(RouteShape::named('engine.trash.bulkDestroy')->uri())->toEndWith('engine/trash/bulk-delete')
        ->and(RouteShape::named('engine.trash.restore')->methods())->toContain('PUT');
});
