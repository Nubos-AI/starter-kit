<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Http\Middleware\Api\ResolveApiContext;
use App\Models\User;
use App\Support\Sso\SsoConnectionLookup;
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

it('lets an unauthenticated api call pass on to the authentication middleware', function (): void {
    $response = app(ResolveApiContext::class)->handle(($this->requestFor)(null), $this->reached);

    expect($response->getContent())->toBe('reached');
});

it('refuses a passkey login of a service account', function (): void {
    $passkey = ModelStub::make(Passkey::class, [
        'id' => ModelStub::ulid('service-passkey'),
    ], ['user' => ($this->userWith)(UserStatus::Accepted, true)]);

    expect(Passkeys::allowsLogin(Request::create('/passkey'), $passkey))->toBeFalse();
});

it('allows a passkey login of an accepted user whose organisation enforces no single sign-on', function (): void {
    $connections = Mockery::mock(SsoConnectionLookup::class);
    $connections->shouldReceive('forTenant')->once()->andReturnNull();
    app()->instance(SsoConnectionLookup::class, $connections);
    $passkey = ModelStub::make(Passkey::class, [
        'id' => ModelStub::ulid('accepted-passkey'),
    ], ['user' => ($this->userWith)(UserStatus::Accepted)]);

    expect(Passkeys::allowsLogin(Request::create('/passkey'), $passkey))->toBeTrue();
});
