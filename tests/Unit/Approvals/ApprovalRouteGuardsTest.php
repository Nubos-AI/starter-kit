<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\PromotionRun;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('guards every anchor approval definition route with the configure permission and the live tenant', function (string $route): void {
    $shape = RouteShape::named($route);

    expect($shape->hasDeclaredMiddleware('permission:approvals.configure'))->toBeTrue()
        ->and($shape->hasDeclaredMiddleware('live-tenant'))->toBeTrue();
})->with([
    'index' => 'engine.approval-definitions.index',
    'update' => 'engine.approval-definitions.update',
    'destroy' => 'engine.approval-definitions.destroy',
]);

it('lets only a definition writing verb change an anchor approval definition', function (): void {
    expect(RouteShape::named('engine.approval-definitions.index')->methods())->toBe(['GET', 'HEAD'])
        ->and(RouteShape::named('engine.approval-definitions.update')->methods())->toBe(['PUT'])
        ->and(RouteShape::named('engine.approval-definitions.destroy')->methods())->toBe(['DELETE']);
});

it('constrains every approval process route parameter to a ulid', function (string $name): void {
    $pattern = (string) (RouteShape::named($name)->constraints()['approval'] ?? '');

    expect(preg_match("#^(?:{$pattern})$#", '01JBRT0000000000000000000A'))->toBe(1)
        ->and(preg_match("#^(?:{$pattern})$#", '550e8400-e29b-41d4-a716-446655440000'))->toBe(0)
        ->and(preg_match("#^(?:{$pattern})$#", '42'))->toBe(0)
        ->and(preg_match("#^(?:{$pattern})$#", '..%2F01JBRT0000000000000000000A'))->toBe(0);
})->with([
    'show' => 'engine.approvals.show',
    'decide' => 'engine.approvals.decide',
    'cancel' => 'engine.approvals.cancel',
]);

it('carries no configure permission on the approval decision routes because a decider is not a configurator', function (string $route): void {
    expect(RouteShape::named($route)->hasDeclaredMiddleware('permission:approvals.configure'))->toBeFalse();
})->with([
    'index' => 'engine.approvals.index',
    'show' => 'engine.approvals.show',
    'decide' => 'engine.approvals.decide',
    'cancel' => 'engine.approvals.cancel',
]);

it('binds the anchor kind parameter to the backed enum so an unknown kind never reaches the controller', function (): void {
    $bound = function (string $kind): Route {
        $route = app(Router::class)->getRoutes()->getByName('engine.approval-definitions.update');
        $route->bind(Request::create("/acme/engine/approval-definitions/{$kind}", 'PUT'));
        app(Router::class)->substituteImplicitBindings($route);

        return $route;
    };

    expect($bound('promotion')->parameter('kind'))->toBe(ApprovalAnchorKind::Promotion)
        ->and(fn (): Route => $bound('nonsense'))->toThrow(BackedEnumCaseNotFoundException::class);
});

it('maps only the promotion run onto an anchor kind and every other model onto none', function (): void {
    expect(ApprovalAnchorKind::forModelClass(PromotionRun::class))->toBe(ApprovalAnchorKind::Promotion)
        ->and(ApprovalAnchorKind::forModelClass('App\\Models\\CustomRecord'))->toBeNull()
        ->and(ApprovalAnchorKind::forModelClass(null))->toBeNull()
        ->and(ApprovalAnchorKind::Promotion->modelClass())->toBe(PromotionRun::class)
        ->and(ApprovalAnchorKind::Promotion->label())->toBe('Promotion');
});
