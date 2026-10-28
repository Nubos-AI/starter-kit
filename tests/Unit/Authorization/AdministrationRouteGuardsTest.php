<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTeamContext;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards the role list with the view permission', function (): void {
    $route = RouteShape::named('engine.roles.index');

    expect($route->hasDeclaredMiddleware('permission:roles.view'))->toBeTrue()
        ->and($route->handledBy())->toContain('RolesController@index');
});

it('guards creating a role with the policy instead of a bare permission', function (): void {
    foreach (['engine.roles.create', 'engine.roles.store'] as $name) {
        expect(RouteShape::named($name)->hasDeclaredMiddleware('can:create,App\Models\Role'))->toBeTrue();
    }
});

it('guards every write on a role with the role policy', function (): void {
    expect(RouteShape::named('engine.roles.update')->hasDeclaredMiddleware('can:update,role'))->toBeTrue()
        ->and(RouteShape::named('engine.roles.edit')->hasDeclaredMiddleware('can:update,role'))->toBeTrue()
        ->and(RouteShape::named('engine.roles.destroy')->hasDeclaredMiddleware('can:delete,role'))->toBeTrue();
});

it('guards the permission mapping of a role with the same update policy', function (): void {
    expect(RouteShape::named('engine.roles.permissions.update')->hasDeclaredMiddleware('can:update,role'))->toBeTrue();
});

it('guards the user list and the user options with the member view permission', function (): void {
    expect(RouteShape::named('engine.users.index')->hasDeclaredMiddleware('permission:members.view'))->toBeTrue()
        ->and(RouteShape::named('engine.users.options')->hasDeclaredMiddleware('permission:members.view'))->toBeTrue();
});

it('guards every invitation route with the invite permission', function (): void {
    foreach (['engine.users.invite.create', 'engine.users.invite.store', 'engine.users.invite.resend'] as $name) {
        expect(RouteShape::named($name)->hasDeclaredMiddleware('permission:members.invite'))->toBeTrue();
    }
});

it('leaves the per user routes to the controller so the reach can be checked on the row', function (): void {
    foreach (['engine.users.edit', 'engine.users.update', 'engine.users.destroy', 'engine.users.status.update', 'engine.users.password.update'] as $name) {
        $declared = RouteShape::named($name)->declaredMiddleware();

        expect(array_filter($declared, static fn (string $entry): bool => str_starts_with($entry, 'permission:')))->toBeEmpty();
    }
});

it('guards the team routes with the matching team permissions', function (): void {
    expect(RouteShape::named('engine.teams.index')->hasDeclaredMiddleware('permission:teams.view'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.create')->hasDeclaredMiddleware('permission:teams.create'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.store')->hasDeclaredMiddleware('permission:teams.create'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.edit')->hasDeclaredMiddleware('permission:teams.update'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.update')->hasDeclaredMiddleware('permission:teams.update'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.destroy')->hasDeclaredMiddleware('permission:teams.delete'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.bulkDestroy')->hasDeclaredMiddleware('permission:teams.delete'))->toBeTrue();
});

it('keeps moving a team behind its own reparent permission', function (): void {
    $route = RouteShape::named('engine.teams.reparent');

    expect($route->hasDeclaredMiddleware('permission:teams.reparent'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('permission:teams.update'))->toBeFalse();
});

it('guards the team access rules with the manage permission and the records capability', function (): void {
    expect(RouteShape::named('engine.teams.access-rules.index')->hasDeclaredMiddleware('permission:teams.manage'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.access-rules.update')->hasDeclaredMiddleware('permission:teams.manage'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.access-rules.update')->hasDeclaredMiddleware('capability:records'))->toBeTrue()
        ->and(RouteShape::named('engine.teams.access-rules.destroy')->hasDeclaredMiddleware('permission:teams.manage'))->toBeTrue();
});

it('resolves tenant and team context before a role or user is bound', function (): void {
    foreach (['engine.roles.update', 'engine.users.update', 'engine.teams.update'] as $name) {
        $route = RouteShape::named($name);

        expect($route->runsBefore(ResolveTenantContext::class, SubstituteBindings::class))->toBeTrue()
            ->and($route->runsBefore(ResolveTeamContext::class, SubstituteBindings::class))->toBeTrue();
    }
});
