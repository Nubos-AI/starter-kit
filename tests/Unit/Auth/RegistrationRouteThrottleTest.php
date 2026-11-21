<?php

declare(strict_types=1);

use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('throttles the unauthenticated registration endpoint', function (): void {
    expect(RouteShape::named('register.store')->declaredMiddleware())->toContain('throttle:registration');
});

it('throttles the password confirmation endpoint', function (): void {
    expect(RouteShape::named('password.confirm.store')->declaredMiddleware())->toContain('throttle:6,1');
});
