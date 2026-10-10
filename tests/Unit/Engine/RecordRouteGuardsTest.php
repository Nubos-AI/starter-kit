<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Http\Middleware\ResolveTeamContext;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards the record update route with the record policy and the records capability', function (): void {
    $route = RouteShape::named('engine.records.update');

    expect($route->hasDeclaredMiddleware('can:update,record'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue()
        ->and($route->handledBy())->toContain('RecordWriteController@update');
});

it('guards the record create route with the object type permission', function (): void {
    $route = RouteShape::named('engine.records.store');

    expect($route->hasDeclaredMiddleware('permission:{objectType}.create'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue();
});

it('guards the duplicate route with both the view policy and the create permission', function (): void {
    $route = RouteShape::named('engine.records.duplicate');

    expect($route->hasDeclaredMiddleware('can:view,record'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('permission:{record}.create'))->toBeTrue();
});

it('enables row access enforcement before route model binding resolves a record', function (): void {
    $route = RouteShape::named('engine.records.update');

    expect($route->runsBefore(EnforceRecordAccessRules::class, SubstituteBindings::class))->toBeTrue();
});

it('resolves tenant and team context before route model binding', function (): void {
    $route = RouteShape::named('engine.records.update');

    expect($route->runsBefore(ResolveTenantContext::class, SubstituteBindings::class))->toBeTrue()
        ->and($route->runsBefore(ResolveTeamContext::class, SubstituteBindings::class))->toBeTrue();
});

it('enables row access enforcement on the file routes as well', function (): void {
    $route = RouteShape::named('files.index');

    expect($route->runsBefore(EnforceRecordAccessRules::class, SubstituteBindings::class))->toBeTrue()
        ->and($route->handledBy())->toContain('RecordFilesController@index');
});

it('leaves the record delete route to the controller instead of a route guard', function (): void {
    $route = RouteShape::named('engine.records.destroy');

    expect($route->declaredMiddleware())->not->toContain('can:delete,record')
        ->and($route->hasDeclaredMiddleware('capability:records'))->toBeTrue();
});
