<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(UserStatus, bool):User */
    $this->userWith = fn (UserStatus $status, bool $isService = false): User => AccessContext::user(
        $this->tenant,
        ['status' => $status->value, 'is_service' => $isService],
        $status->value.($isService ? '-service' : ''),
    );

    /** @var callable(?User):Request */
    $this->requestFor = function (?User $user): Request {
        $request = Request::create('/api/v1/records', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('names exactly the accepted status as the one that may authenticate', function (): void {
    expect(UserStatus::Accepted->canAuthenticate())->toBeTrue()
        ->and(UserStatus::Invited->canAuthenticate())->toBeFalse()
        ->and(UserStatus::Blocked->canAuthenticate())->toBeFalse()
        ->and(UserStatus::Deleted->canAuthenticate())->toBeFalse();
});

it('refuses an api call of a blocked user before any controller runs', function (): void {
    $request = ($this->requestFor)(($this->userWith)(UserStatus::Blocked));

    expect(fn (): Response => (new ResolveApiContext)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
});

it('refuses an api call of a user who has not accepted the invitation', function (): void {
    $request = ($this->requestFor)(($this->userWith)(UserStatus::Invited));

    expect(fn (): Response => (new ResolveApiContext)->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
});

it('lets an accepted user through and unbinds the tenant of the previous request', function (): void {
    $request = ($this->requestFor)(($this->userWith)(UserStatus::Accepted));

    $response = (new ResolveApiContext)->handle($request, $this->reached);

    expect($response->getContent())->toBe('reached')
        ->and(app()->bound('current_tenant'))->toBeFalse();
});

it('lets an unauthenticated api call pass on to the authentication middleware', function (): void {
    $response = (new ResolveApiContext)->handle(($this->requestFor)(null), $this->reached);

    expect($response->getContent())->toBe('reached');
});

it('refuses a passkey login of a blocked user', function (): void {
    $passkey = ModelStub::make(Passkey::class, [
        'id' => ModelStub::ulid('passkey'),
    ], ['user' => ($this->userWith)(UserStatus::Blocked)]);

    expect(Passkeys::allowsLogin(Request::create('/passkey'), $passkey))->toBeFalse();
});

it('refuses a passkey login of a service account', function (): void {
    $passkey = ModelStub::make(Passkey::class, [
        'id' => ModelStub::ulid('service-passkey'),
    ], ['user' => ($this->userWith)(UserStatus::Accepted, true)]);

    expect(Passkeys::allowsLogin(Request::create('/passkey'), $passkey))->toBeFalse();
});

it('allows a passkey login of an accepted user', function (): void {
    $passkey = ModelStub::make(Passkey::class, [
        'id' => ModelStub::ulid('accepted-passkey'),
    ], ['user' => ($this->userWith)(UserStatus::Accepted)]);

    expect(Passkeys::allowsLogin(Request::create('/passkey'), $passkey))->toBeTrue();
});
