<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTeamContext;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards every administration route with its declared permission or policy', function (string $routeName, string $middleware): void {
    expect(RouteShape::named($routeName)->hasDeclaredMiddleware($middleware))->toBeTrue();
})->with([
    'role list' => ['engine.roles.index', 'permission:roles.view'],
    'role create form' => ['engine.roles.create', 'can:create,App\Models\Role'],
    'role store' => ['engine.roles.store', 'can:create,App\Models\Role'],
    'role update' => ['engine.roles.update', 'can:update,role'],
    'role edit form' => ['engine.roles.edit', 'can:update,role'],
    'role delete' => ['engine.roles.destroy', 'can:delete,role'],
    'role permission mapping' => ['engine.roles.permissions.update', 'can:update,role'],
    'user list' => ['engine.users.index', 'permission:members.view'],
    'user options' => ['engine.users.options', 'permission:members.view'],
    'invitation form' => ['engine.users.invite.create', 'permission:members.invite'],
    'invitation store' => ['engine.users.invite.store', 'permission:members.invite'],
    'invitation resend' => ['engine.users.invite.resend', 'permission:members.invite'],
    'team list' => ['engine.teams.index', 'permission:teams.view'],
    'team create form' => ['engine.teams.create', 'permission:teams.create'],
    'team store' => ['engine.teams.store', 'permission:teams.create'],
    'team edit form' => ['engine.teams.edit', 'permission:teams.update'],
    'team update' => ['engine.teams.update', 'permission:teams.update'],
    'team delete' => ['engine.teams.destroy', 'permission:teams.delete'],
    'team bulk delete' => ['engine.teams.bulkDestroy', 'permission:teams.delete'],
    'team access rule list' => ['engine.teams.access-rules.index', 'permission:teams.manage'],
    'team access rule update' => ['engine.teams.access-rules.update', 'permission:teams.manage'],
    'team access rule update capability' => ['engine.teams.access-rules.update', 'capability:records'],
    'team access rule delete' => ['engine.teams.access-rules.destroy', 'permission:teams.manage'],
]);

it('hands the role list to the roles controller', function (): void {
    expect(RouteShape::named('engine.roles.index')->handledBy())->toContain('RolesController@index');
});

it('leaves the per user routes to the controller so the reach can be checked on the row', function (): void {
    foreach (['engine.users.edit', 'engine.users.update', 'engine.users.destroy', 'engine.users.status.update', 'engine.users.password.update'] as $name) {
        $declared = RouteShape::named($name)->declaredMiddleware();

        expect(array_filter($declared, static fn (string $entry): bool => str_starts_with($entry, 'permission:')))->toBeEmpty();
    }
});

it('keeps moving a team behind its own reparent permission', function (): void {
    $route = RouteShape::named('engine.teams.reparent');

    expect($route->hasDeclaredMiddleware('permission:teams.reparent'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('permission:teams.update'))->toBeFalse();
});

it('resolves tenant and team context before a role or user is bound', function (): void {
    foreach (['engine.roles.update', 'engine.users.update', 'engine.teams.update'] as $name) {
        $route = RouteShape::named($name);

        expect($route->runsBefore(ResolveTenantContext::class, SubstituteBindings::class))->toBeTrue()
            ->and($route->runsBefore(ResolveTeamContext::class, SubstituteBindings::class))->toBeTrue();
    }
});
