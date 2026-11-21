<?php

declare(strict_types=1);

use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('throttles the unauthenticated password reset endpoints', function (string $routeName): void {
    expect(RouteShape::named($routeName)->declaredMiddleware())->toContain('throttle:6,1');
})->with([
    'reset link request' => 'password.email',
    'password reset submission' => 'password.update',
]);
