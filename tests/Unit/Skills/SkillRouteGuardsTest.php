<?php

declare(strict_types=1);

use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards every skill route with the permission its verb requires', function (string $route, string $permission): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware("permission:{$permission}"))->toBeTrue();
})->with([
    'listing' => ['engine.skills.index', 'skills.view'],
    'create form' => ['engine.skills.create', 'skills.create'],
    'storing' => ['engine.skills.store', 'skills.create'],
    'edit form' => ['engine.skills.edit', 'skills.view'],
    'updating' => ['engine.skills.update', 'skills.update'],
    'bulk deleting' => ['engine.skills.bulkDestroy', 'skills.delete'],
    'deleting' => ['engine.skills.destroy', 'skills.delete'],
]);

it('constrains every bound skill parameter to a ulid', function (string $route): void {
    expect(RouteShape::named($route)->constraints())->toHaveKey('skill');
})->with([
    'edit form' => 'engine.skills.edit',
    'updating' => 'engine.skills.update',
    'deleting' => 'engine.skills.destroy',
]);

it('keeps the bulk delete on its own path so it never collides with a ulid bound route', function (): void {
    expect(RouteShape::nameDispatchedFor('POST', '/acme/engine/skills/bulk-delete'))->toBe('engine.skills.bulkDestroy')
        ->and(RouteShape::nameDispatchedFor('DELETE', '/acme/engine/skills/bulk-delete'))->toBeNull()
        ->and(RouteShape::nameDispatchedFor('PUT', '/acme/engine/skills/bulk-delete'))->toBeNull();
});
