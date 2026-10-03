<?php

declare(strict_types=1);

use App\Actions\Api\BulkRevokeApiTokensAction;
use App\Actions\Api\CreateApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Actions\Api\RotateApiTokenAction;
use App\Http\Controllers\Api\ApiTokensController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiTokenPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->createApiToken = Mockery::mock(CreateApiTokenAction::class);
    $this->rotateApiToken = Mockery::mock(RotateApiTokenAction::class);
    $this->revokeApiToken = Mockery::mock(RevokeApiTokenAction::class);
    $this->bulkRevokeApiTokens = Mockery::mock(BulkRevokeApiTokensAction::class);

    $this->controller = new ApiTokensController(
        $this->createApiToken,
        $this->rotateApiToken,
        $this->revokeApiToken,
        $this->bulkRevokeApiTokens,
        new ApiTokenPresenter,
    );

    /** @var callable(bool, ?Tenant):User */
    $this->actor = function (bool $escalated = false, ?Tenant $tenant = null): User {
        $tenant ??= $this->tenant;

        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid('token-admin'),
            'tenant_id' => $tenant->getKey(),
        ], ['tenant' => $tenant]);

        $user->escalated = $escalated;

        return $user;
    };

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/engine/api-tokens', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);
        $request->setLaravelSession(new Store('testing', new ArraySessionHandler(60)));

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses every token management verb to an actor without the manage permission', function (string $method, array $arguments): void {
    $resolver = AccessContext::grant('records.view');
    $request = ($this->requestOf)(($this->actor)());

    $this->createApiToken->shouldNotReceive('execute');
    $this->rotateApiToken->shouldNotReceive('execute');
    $this->revokeApiToken->shouldNotReceive('execute');
    $this->bulkRevokeApiTokens->shouldNotReceive('execute');

    expect(fn (): mixed => $this->controller->{$method}($request, ...$arguments))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['api-tokens.manage']);
})->with([
    'index' => ['index', []],
    'create' => ['create', []],
    'store' => ['store', []],
    'rotate' => ['rotate', ['17']],
    'destroy' => ['destroy', ['17']],
    'bulk destroy' => ['bulkDestroy', []],
]);

it('refuses every token management verb to a guest', function (string $method, array $arguments): void {
    AccessContext::grant('api-tokens.manage');
    $request = ($this->requestOf)(null);

    expect(fn (): mixed => $this->controller->{$method}($request, ...$arguments))
        ->toThrow(AuthorizationException::class);
})->with([
    'index' => ['index', []],
    'store' => ['store', []],
    'rotate' => ['rotate', ['17']],
    'destroy' => ['destroy', ['17']],
]);

it('refuses token management to an actor without a tenant context', function (): void {
    AccessContext::grant('api-tokens.manage');

    $user = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('token-admin'),
        'tenant_id' => $this->tenant->getKey(),
    ], ['tenant' => null]);

    expect(fn (): mixed => $this->controller->index(($this->requestOf)($user)))
        ->toThrow(AuthorizationException::class);
});

it('admits an escalated authority without asking for the manage permission', function (): void {
    $resolver = AccessContext::grant();
    $request = ($this->requestOf)(($this->actor)(escalated: true));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->index($request));

    expect($resolver->askedFor)->toBe([])
        ->and($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue();
});

it('lists only the tokens of users inside the own tenant', function (): void {
    AccessContext::grant('api-tokens.manage');
    $request = ($this->requestOf)(($this->actor)());

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->index($request));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->tenant->getKey());
});

it('hands the acting user to the create action so a token can never exceed its creator', function (): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    $request = ($this->requestOf)($actor);
    $request->merge(['name' => 'Integration']);

    $received = null;

    $this->createApiToken->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (User $creator, Tenant $tenant, array $input) use (&$received): never {
            $received = ['creator' => $creator, 'tenant' => $tenant, 'input' => $input];

            throw new AuthorizationException('stopped after the hand-off');
        });

    expect(fn (): mixed => $this->controller->store($request))->toThrow(AuthorizationException::class)
        ->and($received['creator'])->toBe($actor)
        ->and($received['tenant']->getKey())->toBe($this->tenant->getKey())
        ->and($received['input']['name'])->toBe('Integration');
});

it('offers no system object type when a token matrix is put together', function (): void {
    AccessContext::grant('api-tokens.manage');
    $request = ($this->requestOf)(($this->actor)());

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->create($request));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->sql)->toContain('"is_system" = ?')
        ->and($shape->bindings[0])->toBeFalsy();
});
