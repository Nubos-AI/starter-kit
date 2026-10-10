<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('demands the matching activity type permission on every endpoint', function (string $route, string $permission): void {
    $shape = RouteShape::named($route);

    expect($shape->hasDeclaredMiddleware("permission:{$permission}"))->toBeTrue()
        ->and($shape->resolvedMiddleware())->toContain(Authenticate::class);
})->with([
    'index' => ['engine.activity-types.index', 'activity-types.view'],
    'edit' => ['engine.activity-types.edit', 'activity-types.view'],
    'create' => ['engine.activity-types.create', 'activity-types.create'],
    'store' => ['engine.activity-types.store', 'activity-types.create'],
    'update' => ['engine.activity-types.update', 'activity-types.update'],
    'destroy' => ['engine.activity-types.destroy', 'activity-types.delete'],
    'bulk destroy' => ['engine.activity-types.bulkDestroy', 'activity-types.delete'],
]);

it('never lets a viewing permission alone reach a writing endpoint', function (string $route): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware('permission:activity-types.view'))->toBeFalse();
})->with([
    'engine.activity-types.store',
    'engine.activity-types.update',
    'engine.activity-types.destroy',
    'engine.activity-types.bulkDestroy',
]);

it('runs the bulk delete through a post endpoint of its own instead of a wildcard delete', function (): void {
    $shape = RouteShape::named('engine.activity-types.bulkDestroy');

    expect($shape->methods())->toContain('POST')
        ->and($shape->uri())->toEndWith('engine/activity-types/bulk-delete')
        ->and($shape->handledBy())->toContain('ActivityTypesController@bulkDestroy');
});
