<?php

declare(strict_types=1);

use App\Actions\Api\BulkRevokeApiTokensAction;
use App\Actions\Api\CreatePersonalApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Enums\Api\ApiTokenAbility;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
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

it('keeps the token collection of a user bound to that user alone', function (): void {
    $shape = QueryShape::of($this->owner->tokens());

    expect($shape->targets('personal_access_tokens'))->toBeTrue()
        ->and($shape->sql)->toContain('"tokenable_id" = ?')
        ->and($shape->sql)->toContain('"tokenable_type" = ?')
        ->and($shape->bindings)->toContain((string) $this->owner->getKey())
        ->and($shape->bindings)->toContain((new User)->getMorphClass());
});

it('revokes only inside the token collection of the acting user', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => (new RevokeApiTokenAction)->execute($this->owner, '17'));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"tokenable_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->owner->getKey())
        ->and($shape->bindings)->toContain('17');
});

it('bulk revokes only tokens whose owner belongs to the tenant of the actor', function (): void {
    $action = new BulkRevokeApiTokensAction(new RevokeApiTokenAction);

    $shape = QueryShape::attemptedBy(fn (): mixed => $action->execute($this->owner, ['ids' => [17, 18]]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->bindings)->toContain((string) $this->tenant->getKey());
});

it('rejects a personal token that names an ability outside the enum', function (array $abilities): void {
    expect(fn (): NewAccessToken => (new CreatePersonalApiTokenAction)->execute($this->owner, [
        'name' => 'Laptop',
        'abilities' => $abilities,
    ]))->toThrow(ValidationException::class);
})->with([
    'wildcard' => [['*']],
    'unknown scope' => [['invoices:read']],
    'empty list' => [[]],
]);

it('rejects a personal token without a name and one whose expiry lies in the past', function (array $input): void {
    expect(fn (): NewAccessToken => (new CreatePersonalApiTokenAction)->execute($this->owner, $input))
        ->toThrow(ValidationException::class);
})->with([
    'no name' => [['abilities' => ['records:read']]],
    'expired' => [['name' => 'Laptop', 'abilities' => ['records:read'], 'expiresAt' => '2000-01-01T00:00:00Z']],
]);

it('accepts every ability the enum offers and issues the token for the acting user alone', function (): void {
    $abilities = array_map(static fn (ApiTokenAbility $ability): string => $ability->value, ApiTokenAbility::cases());

    $shape = QueryShape::attemptedBy(fn (): NewAccessToken => (new CreatePersonalApiTokenAction)->execute($this->owner, [
        'name' => 'Laptop',
        'abilities' => $abilities,
    ]));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('insert into "personal_access_tokens"')
        ->and($shape->bindings)->toContain((string) $this->owner->getKey())
        ->and($shape->bindings)->toContain((new User)->getMorphClass());
});
