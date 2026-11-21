<?php

declare(strict_types=1);

use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('throttles every settings route that checks the current password', function (string $routeName): void {
    expect(RouteShape::named($routeName)->declaredMiddleware())->toContain('throttle:6,1');
})->with([
    'password change' => 'user-password.update',
    'own account deletion' => 'profile.destroy',
    'profile update with an email change' => 'profile.update',
]);
