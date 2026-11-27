<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Http\Middleware\Api\RejectTransientToken;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/api/v1/companies', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    Auth::forgetUser();
    AccessContext::forgetTenant();
});

it('rejects a blocked, invited or deleted token holder with an authentication failure', function (UserStatus $status): void {
    $request = ($this->requestOf)(AccessContext::user($this->tenant, ['status' => $status]));

    expect(fn (): Response => app(ResolveApiContext::class)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
})->with([
    UserStatus::Blocked,
    UserStatus::Invited,
    UserStatus::Deleted,
]);

it('rejects a session bound transient token so a browser session can never drive the api', function (): void {
    $user = AccessContext::user($this->tenant, ['status' => UserStatus::Accepted]);
    $user->withAccessToken(new TransientToken);

    expect(fn (): Response => (new RejectTransientToken)->handle(($this->requestOf)($user), $this->reached))
        ->toThrow(AuthenticationException::class);
});
