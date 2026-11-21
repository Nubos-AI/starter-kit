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
use App\Support\Api\ApiTokenRevocationGuard;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Teams\TeamSegment;
use App\Support\Users\ManageableUserDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->createApiToken = Mockery::mock(CreateApiTokenAction::class);
    $this->rotateApiToken = Mockery::mock(RotateApiTokenAction::class);
    $this->revokeApiToken = Mockery::mock(RevokeApiTokenAction::class);
    $this->bulkRevokeApiTokens = Mockery::mock(BulkRevokeApiTokensAction::class);
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->userDirectory = Mockery::mock(ManageableUserDirectory::class);
    $this->revocationGuard = new ApiTokenRevocationGuard($this->subsetGuard, $this->userDirectory);

    $this->controller = new ApiTokensController(
        $this->createApiToken,
        $this->rotateApiToken,
        $this->revokeApiToken,
        $this->bulkRevokeApiTokens,
        new ApiTokenPresenter,
        $this->revocationGuard,
    );

    /** @var callable(list<array{id: int, owner: string, isService: bool}>):void */
    $this->serveTokens = function (array $tokens): void {
        StaticQueryConnection::install(function (string $sql, array $bindings) use ($tokens): array {
            if (str_contains($sql, 'personal_access_tokens')) {
                return array_map(static fn (array $token): array => [
                    'id' => $token['id'],
                    'tokenable_type' => (new User)->getMorphClass(),
                    'tokenable_id' => ModelStub::ulid($token['owner']),
                    'name' => "token-{$token['id']}",
                    'abilities' => '["records:read"]',
                ], $tokens);
            }

            if (!str_contains($sql, '"users"')) {
                return [];
            }

            $owners = [];

            foreach ($tokens as $token) {
                $owners[ModelStub::ulid($token['owner'])] = [
                    'id' => ModelStub::ulid($token['owner']),
                    'tenant_id' => (string) $this->tenant->getKey(),
                    'is_service' => $token['isService'],
                ];
            }

            $requested = array_values(array_filter($owners, static fn (array $owner): bool => in_array($owner['id'], $bindings, true)));

            return $requested === [] ? array_values($owners) : $requested;
        });
    };

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
    URL::defaults([TeamSegment::key() => null]);
    StaticQueryConnection::uninstall();
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

it('hands the acting user to the rotate action so a reissued token can never exceed the rotating actor', function (): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    $request = ($this->requestOf)($actor);
    $serviceUserId = ModelStub::ulid('service-user');

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'personal_access_tokens') => [[
            'id' => 17,
            'tokenable_type' => (new User)->getMorphClass(),
            'tokenable_id' => $serviceUserId,
            'name' => 'nightly-import',
            'abilities' => '["companies:read"]',
        ]],
        str_contains($sql, '"users"') => [[
            'id' => $serviceUserId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'is_service' => true,
        ]],
        default => [],
    });

    $received = null;

    $this->rotateApiToken->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (User $rotatingActor, User $serviceUser, string $tokenId) use (&$received): never {
            $received = ['actor' => $rotatingActor, 'serviceUser' => $serviceUser, 'tokenId' => $tokenId];

            throw new AuthorizationException('stopped after the hand-off');
        });

    expect(fn (): mixed => $this->controller->rotate($request, '17'))->toThrow(AuthorizationException::class)
        ->and($received['actor'])->toBe($actor)
        ->and($received['serviceUser']->getKey())->toBe($serviceUserId)
        ->and($received['tokenId'])->toBe('17');
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

it('refuses to revoke the personal token of an account that holds more rights than the token manager', function (): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    ($this->serveTokens)([['id' => 17, 'owner' => 'tenant-owner', 'isService' => false]]);

    $this->subsetGuard->shouldReceive('maySteerAccount')
        ->once()
        ->withArgs(static fn (User $judging, User $owner): bool => $judging === $actor && $owner->getKey() === ModelStub::ulid('tenant-owner'))
        ->andReturnFalse();
    $this->revokeApiToken->shouldNotReceive('execute');

    expect(fn (): mixed => $this->controller->destroy(($this->requestOf)($actor), '17'))
        ->toThrow(AuthorizationException::class, 'Das persönliche API-Token dieses Kontos können Sie nicht widerrufen, weil es mehr Rechte hat als Sie.');
});

it('revokes the personal token of an account the token manager may steer', function (): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    ($this->serveTokens)([['id' => 17, 'owner' => 'colleague', 'isService' => false]]);

    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->andReturnTrue();
    $this->revokeApiToken->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (User $owner, string $tokenId): bool => $owner->getKey() === ModelStub::ulid('colleague') && $tokenId === '17');

    $this->controller->destroy(($this->requestOf)($actor), '17');
});

it('revokes a service token and an own personal token without judging the owner account', function (string $owner, bool $isService): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    ($this->serveTokens)([['id' => 17, 'owner' => $owner, 'isService' => $isService]]);

    $this->subsetGuard->shouldNotReceive('maySteerAccount');
    $this->revokeApiToken->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (User $tokenOwner, string $tokenId): bool => $tokenOwner->getKey() === ModelStub::ulid($owner) && $tokenId === '17');

    $this->controller->destroy(($this->requestOf)($actor), '17');
})->with([
    'service token' => ['integration', true],
    'own personal token' => ['token-admin', false],
]);

it('leaves the personal token of an account above the token manager out of a bulk revocation', function (): void {
    $actor = ($this->actor)();
    ($this->serveTokens)([
        ['id' => 17, 'owner' => 'tenant-owner', 'isService' => false],
        ['id' => 18, 'owner' => 'integration', 'isService' => true],
    ]);

    $this->subsetGuard->shouldReceive('maySteerAccount')
        ->once()
        ->withArgs(static fn (User $judging, User $owner): bool => $owner->getKey() === ModelStub::ulid('tenant-owner'))
        ->andReturnFalse();
    $this->revokeApiToken->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (User $owner, string $tokenId): bool => $owner->getKey() === ModelStub::ulid('integration') && $tokenId === '18');

    $revoked = (new BulkRevokeApiTokensAction($this->revokeApiToken, $this->revocationGuard))
        ->execute($actor, ['ids' => [17, 18]]);

    expect($revoked->modelKeys())->toBe([18]);
});

it('marks the personal token of an account above the token manager as not revocable and names the reason', function (): void {
    AccessContext::grant('api-tokens.manage');
    $actor = ($this->actor)();
    ($this->serveTokens)([
        ['id' => 17, 'owner' => 'tenant-owner', 'isService' => false],
        ['id' => 18, 'owner' => 'integration', 'isService' => true],
        ['id' => 19, 'owner' => 'token-admin', 'isService' => false],
    ]);

    $this->userDirectory->shouldReceive('roleIdsByUser')->andReturn([]);
    $this->userDirectory->shouldReceive('teamIdsByUser')->andReturn([]);
    $this->subsetGuard->shouldReceive('steerableAccountKeys')
        ->once()
        ->withArgs(static fn (User $judging, array $keys): bool => $keys === [ModelStub::ulid('tenant-owner')])
        ->andReturn([]);

    $request = ($this->requestOf)($actor);
    $props = $this->controller->index($request)->toResponse($request)->original->getData()['page']['props'];
    $rows = collect($props['tokens'])->keyBy('id');

    expect($rows['17']['canRevoke'])->toBeFalse()
        ->and($rows['17']['revokeReason'])->toBe('Dieses Konto hat mehr Rechte als Sie.')
        ->and($rows['18']['canRevoke'])->toBeTrue()
        ->and($rows['18']['revokeReason'])->toBeNull()
        ->and($rows['19']['canRevoke'])->toBeTrue();
});
