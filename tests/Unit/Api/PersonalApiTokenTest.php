<?php

declare(strict_types=1);

use App\Actions\Api\BulkRevokeApiTokensAction;
use App\Actions\Api\CreatePersonalApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Enums\Api\ApiTokenAbility;
use App\Http\Controllers\Settings\ApiTokensController;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->owner = AccessContext::user($this->tenant, [], 'token-owner');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
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

it('rejects a personal token with an ability outside the enum, without a name or with an expiry in the past', function (array $input): void {
    expect(fn (): NewAccessToken => (new CreatePersonalApiTokenAction)->execute($this->owner, $input))
        ->toThrow(ValidationException::class);
})->with([
    'wildcard ability' => [['name' => 'Laptop', 'abilities' => ['*']]],
    'unknown scope' => [['name' => 'Laptop', 'abilities' => ['invoices:read']]],
    'empty ability list' => [['name' => 'Laptop', 'abilities' => []]],
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

it('rotates a personal token only inside the token collection of the acting user', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => []);
    $request = Request::create('/settings/api-tokens/17/rotate', 'POST');
    $request->setUserResolver(fn (): User => $this->owner);

    expect(fn (): mixed => app(ApiTokensController::class)->rotate($request, '17'))
        ->toThrow(ModelNotFoundException::class)
        ->and($connection->queries[0]['sql'])->toContain('"personal_access_tokens"')
        ->and($connection->queries[0]['bindings'])->toContain((string) $this->owner->getKey())
        ->and($connection->queries[0]['bindings'])->toContain('17')
        ->and($connection->writtenStatements)->toBe([]);
});
