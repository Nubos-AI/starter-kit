<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\PromotionRun;
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
    expect(RouteShape::named('engine.approval-definitions.index')->methods())->toContain('GET')
        ->and(RouteShape::named('engine.approval-definitions.update')->methods())->toContain('PUT')
        ->and(RouteShape::named('engine.approval-definitions.destroy')->methods())->toContain('DELETE');
});

it('constrains every approval process route parameter to a ulid', function (string $route): void {
    expect(RouteShape::named($route)->constraints())->toHaveKey('approval');
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
    expect(ApprovalAnchorKind::tryFrom('promotion'))->toBe(ApprovalAnchorKind::Promotion)
        ->and(ApprovalAnchorKind::tryFrom('nonsense'))->toBeNull()
        ->and(RouteShape::named('engine.approval-definitions.update')->uri())->toContain('{kind}');
});

it('maps only the promotion run onto an anchor kind and every other model onto none', function (): void {
    expect(ApprovalAnchorKind::forModelClass(PromotionRun::class))->toBe(ApprovalAnchorKind::Promotion)
        ->and(ApprovalAnchorKind::forModelClass('App\\Models\\CustomRecord'))->toBeNull()
        ->and(ApprovalAnchorKind::forModelClass(null))->toBeNull()
        ->and(ApprovalAnchorKind::Promotion->modelClass())->toBe(PromotionRun::class)
        ->and(ApprovalAnchorKind::Promotion->label())->toBe('Promotion');
});
