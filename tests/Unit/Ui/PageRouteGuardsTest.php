<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnsurePermission;
use Illuminate\Auth\Middleware\Authenticate;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):list<string> */
    $this->permissionGuardsOf = static fn (string $name): array => array_values(array_filter(
        RouteShape::named($name)->declaredMiddleware(),
        static fn (string $entry): bool => str_starts_with($entry, 'permission:'),
    ));
});

it('locks the import wizard and its history behind the import permission of the bound object type', function (): void {
    foreach (['engine.import.page', 'engine.import.history-page'] as $name) {
        $route = RouteShape::named($name);

        expect($route->hasDeclaredMiddleware('permission:{objectType}.import'))->toBeTrue()
            ->and($route->hasDeclaredMiddleware('capability:import'))->toBeTrue();
    }
});

it('locks the notification rules page and its index behind the rule management permission', function (): void {
    foreach (['notification-rules.edit', 'notification-rules.index'] as $name) {
        $route = RouteShape::named($name);

        expect($route->hasDeclaredMiddleware('permission:{objectType}.rules.manage'))->toBeTrue()
            ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue();
    }
});

it('locks the record create page behind the create permission of the bound object type', function (): void {
    $route = RouteShape::named('engine.records.create');

    expect($route->hasDeclaredMiddleware('permission:{objectType}.create'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue();
});

it('splits the personalization matrix into a viewing and an updating permission', function (): void {
    expect(($this->permissionGuardsOf)('engine.personalization.edit'))->toBe(['permission:organisation.view'])
        ->and(($this->permissionGuardsOf)('engine.personalization.update'))->toBe(['permission:organisation.update']);
});

it('opens the personal pages to every signed in member without a further permission', function (): void {
    foreach (['notifications.edit', 'reminders.page', 'dashboard'] as $name) {
        expect(RouteShape::named($name)->declaredMiddleware())->toContain('auth')
            ->and(($this->permissionGuardsOf)($name))->toBe([]);
    }
});

it('demands a signed in and verified member on every page route', function (): void {
    $names = [
        'engine.import.page',
        'engine.import.history-page',
        'notification-rules.edit',
        'notification-rules.index',
        'notifications.edit',
        'reminders.page',
        'engine.records.show',
        'engine.records.edit',
        'engine.records.create',
        'engine.personalization.edit',
        'engine.personalization.update',
        'dashboard',
    ];

    foreach ($names as $name) {
        $declared = RouteShape::named($name)->declaredMiddleware();

        expect($declared)->toContain('auth')
            ->and($declared)->toContain('verified');
    }
});

it('authenticates before it ever asks for a permission', function (): void {
    expect(RouteShape::named('engine.import.page')
        ->runsBefore(Authenticate::class, EnsurePermission::class.':{objectType}.import'))->toBeTrue()
        ->and(RouteShape::named('engine.personalization.update')
            ->runsBefore(Authenticate::class, EnsurePermission::class.':organisation.update'))->toBeTrue();
});

it('carries the record pages behind the record capability instead of a blanket permission', function (): void {
    foreach (['engine.records.show', 'engine.records.edit'] as $name) {
        expect(RouteShape::named($name)->hasDeclaredMiddleware('capability:records'))->toBeTrue()
            ->and(($this->permissionGuardsOf)($name))->toBe([]);
    }
});

it('serves every team bound page under the active team segment', function (): void {
    foreach (['engine.records.show', 'engine.records.edit', 'engine.records.create', 'reminders.page', 'engine.personalization.edit'] as $name) {
        expect(RouteShape::named($name)->uri())->toStartWith('{activeTeam}/');
    }
});
