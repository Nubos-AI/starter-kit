<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards the field recompute route with the object type update permission and the custom fields capability', function (): void {
    $route = RouteShape::named('engine.object-types.fields.recompute');

    expect($route->hasDeclaredMiddleware('permission:object-types.update'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:custom_fields'))->toBeTrue()
        ->and($route->handledBy())->toContain('FieldRecomputesController@store');
});

it('accepts only a ULID as the field of a recompute', function (): void {
    $constraint = RouteShape::named('engine.object-types.fields.recompute')->constraints()['field'] ?? null;

    expect($constraint)->toBeString()
        ->and((bool) preg_match('#^'.$constraint.'$#', (string) Str::ulid()))->toBeTrue()
        ->and((bool) preg_match('#^'.$constraint.'$#', 'not-a-ulid'))->toBeFalse();
});

it('guards the backfill status route with the object type policy and the custom fields capability', function (): void {
    $route = RouteShape::named('engine.formula-backfills.show');

    expect($route->hasDeclaredMiddleware('can:update,objectType'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:custom_fields'))->toBeTrue()
        ->and($route->handledBy())->toContain('FormulaBackfillsController@show');
});

it('accepts only a ULID as the run of a backfill cancellation', function (): void {
    $constraint = RouteShape::named('engine.formula-backfills.cancel')->constraints()['backfillRun'] ?? null;

    expect($constraint)->toBeString()
        ->and((bool) preg_match('#^'.$constraint.'$#', (string) Str::ulid()))->toBeTrue()
        ->and((bool) preg_match('#^'.$constraint.'$#', 'not-a-ulid'))->toBeFalse();
});

it('leaves the backfill cancellation to the action instead of a route guard', function (): void {
    $route = RouteShape::named('engine.formula-backfills.cancel');

    expect($route->declaredMiddleware())->not->toContain('can:update,backfillRun')
        ->and($route->handledBy())->toContain('FormulaBackfillsController@cancel');
});
