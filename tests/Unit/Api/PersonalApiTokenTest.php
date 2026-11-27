<?php

declare(strict_types=1);

use App\Actions\Api\RevokeApiTokenAction;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->owner = AccessContext::user($this->tenant, [], 'token-owner');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('revokes only inside the token collection of the acting user', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => (new RevokeApiTokenAction)->execute($this->owner, '17'));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"tokenable_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->owner->getKey())
        ->and($shape->bindings)->toContain('17');
});
