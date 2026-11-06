<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Http\Middleware\Api\RejectTransientToken;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
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
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets an accepted token holder through and drops the tenant bound by an earlier request', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);

    $response = (new ResolveApiContext)->handle(($this->requestOf)($user), $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and(app()->bound('current_tenant'))->toBeFalse();
});

it('rejects a blocked, invited or deleted token holder with an authentication failure', function (UserStatus $status): void {
    $user = AccessContext::user($this->tenant, ['status' => $status]);
    $request = ($this->requestOf)($user);

    expect(fn (): Response => (new ResolveApiContext)->handle($request, $this->reached))
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
