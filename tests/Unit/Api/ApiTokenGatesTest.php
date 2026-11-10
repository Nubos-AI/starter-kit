<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Http\Middleware\Api\RejectTransientToken;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(list<string>):PersonalAccessToken */
    $this->personalToken = static function (array $abilities = []): PersonalAccessToken {
        $token = ModelStub::make(PersonalAccessToken::class, ['id' => 7]);
        $token->abilities = $abilities;

        return $token;
    };

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/api/v1/companies', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');

    $this->creatorId = ModelStub::ulid('token-creator');

    /** @var callable(?string):User */
    $this->serviceHolding = function (?string $creatorId): User {
        $token = ModelStub::make(PersonalAccessToken::class, ['id' => 9, 'creator_id' => $creatorId]);
        $token->abilities = ['companies:read'];

        $service = AccessContext::user($this->tenant, ['is_service' => true], 'service-user');
        $service->withAccessToken($token);

        return $service;
    };

    /** @var callable(list<array<string, mixed>>):StaticQueryConnection */
    $this->creatorRows = static fn (array $rows): StaticQueryConnection => StaticQueryConnection::install(
        static fn (): array => $rows,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Auth::forgetUser();
    AccessContext::forgetTenant();
});

it('lets an accepted token holder through and drops the tenant bound by an earlier request', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);

    $response = app(ResolveApiContext::class)->handle(($this->requestOf)($user), $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and(app()->bound('current_tenant'))->toBeFalse();
});

it('rejects a blocked, invited or deleted token holder with an authentication failure', function (UserStatus $status): void {
    $user = AccessContext::user($this->tenant, ['status' => $status]);
    $request = ($this->requestOf)($user);

    expect(fn (): Response => app(ResolveApiContext::class)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class)
        ->and(app()->bound('current_tenant'))->toBeTrue();
})->with([
    UserStatus::Blocked,
    UserStatus::Invited,
    UserStatus::Deleted,
]);

it('rejects a session bound transient token so a browser session can never drive the api', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);
    $user->withAccessToken(new TransientToken);

    $request = ($this->requestOf)($user);

    expect(fn (): Response => (new RejectTransientToken)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
});

it('rejects a caller that presents no token at all', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);
    $request = ($this->requestOf)($user);

    expect(fn (): Response => (new RejectTransientToken)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class)
        ->and(fn (): Response => (new RejectTransientToken)->handle(($this->requestOf)(null), $this->reached))
        ->toThrow(AuthenticationException::class);
});

it('lets a personal access token through', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);
    $user->withAccessToken(($this->personalToken)(['companies:read']));

    $response = (new RejectTransientToken)->handle(($this->requestOf)($user), $this->reached);

    expect($response->getContent())->toBe('reached');
});

it('rejects a service token that names no creator before it reaches any data', function (): void {
    $connection = ($this->creatorRows)([]);
    $request = ($this->requestOf)(($this->serviceHolding)(null));

    expect(fn (): Response => app(ResolveApiContext::class)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class)
        ->and($connection->queries)->toBe([]);
});

it('rejects a service token whose creator is gone, belongs to another tenant or is itself a service identity', function (): void {
    $connection = ($this->creatorRows)([]);
    $request = ($this->requestOf)(($this->serviceHolding)($this->creatorId));

    expect(fn (): Response => app(ResolveApiContext::class)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);

    $lookup = $connection->queries[0];

    expect($lookup['sql'])->toContain('from "users"')
        ->and($lookup['sql'])->toContain('"tenant_id" = ?')
        ->and($lookup['sql'])->toContain('"is_service" = ?')
        ->and($lookup['sql'])->toContain('"deleted_at" is null')
        ->and($lookup['bindings'])->toContain((string) $this->tenant->getKey())
        ->and($lookup['bindings'])->toContain($this->creatorId)
        ->and($lookup['bindings'])->toContain(false);
});

it('rejects a service token whose creator is no longer active', function (UserStatus $status): void {
    ($this->creatorRows)([[
        'id' => $this->creatorId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'is_service' => false,
        'status' => $status->value,
    ]]);
    $request = ($this->requestOf)(($this->serviceHolding)($this->creatorId));

    expect(fn (): Response => app(ResolveApiContext::class)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
})->with([
    UserStatus::Blocked,
    UserStatus::Invited,
    UserStatus::Deleted,
]);

it('lets a service token act as its active creator carrying the token abilities', function (): void {
    ($this->creatorRows)([[
        'id' => $this->creatorId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'is_service' => false,
        'status' => UserStatus::Accepted->value,
    ]]);
    $request = ($this->requestOf)(($this->serviceHolding)($this->creatorId));

    $seen = null;

    app(ResolveApiContext::class)->handle($request, static function (Request $passed) use (&$seen): Response {
        $seen = $passed->user();

        return new Response('reached');
    });

    expect($seen)->toBeInstanceOf(User::class)
        ->and($seen->getKey())->toBe($this->creatorId)
        ->and($seen->is_service)->toBeFalse()
        ->and($seen->currentAccessToken()?->getKey())->toBe(9)
        ->and($seen->tokenCan('companies:read'))->toBeTrue()
        ->and(Auth::user()?->getKey())->toBe($this->creatorId);
});
