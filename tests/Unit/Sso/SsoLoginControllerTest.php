<?php

declare(strict_types=1);

use App\Actions\Sso\CompleteSsoLoginAction;
use App\Actions\Sso\CompleteSsoLoginTestAction;
use App\Actions\Sso\StartSsoLoginAction;
use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoFailure;
use App\Enums\Sso\SsoLoginPurpose;
use App\Enums\Ui\ToastType;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\SsoLoginException;
use App\Http\Controllers\Sso\SsoLoginController;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Teams\TeamSegment;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Support\Header;
use Inertia\Support\SessionKey;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LoginViewResponse;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->start = Mockery::mock(StartSsoLoginAction::class);
    $this->complete = Mockery::mock(CompleteSsoLoginAction::class);
    $this->completeTest = Mockery::mock(CompleteSsoLoginTestAction::class);
    app()->instance(CompleteSsoLoginTestAction::class, $this->completeTest);
    $this->guard = Mockery::mock(StatefulGuard::class);
    $this->loginResponse = Mockery::mock(LoginResponse::class);
    app()->instance(StartSsoLoginAction::class, $this->start);
    app()->instance(CompleteSsoLoginAction::class, $this->complete);
    app()->instance(StatefulGuard::class, $this->guard);
    app()->instance(LoginResponse::class, $this->loginResponse);

    $this->request = static function (string $uri, string $method = 'GET', array $input = [], array $headers = []): Request {
        $request = Request::create($uri, $method, $input);
        $request->headers->add($headers);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        app()->instance('request', $request);

        return $request;
    };

    $this->pendingIn = static function (Request $request, SsoLoginPurpose $purpose): void {
        $request->session()->put(OidcAuthorizationFlow::$sessionKey, (new PendingSsoLogin(
            ModelStub::ulid('connection'),
            ModelStub::ulid('acme'),
            'state-123',
            'nonce-456',
            'verifier-789',
            now()->getTimestamp(),
            $purpose,
            $purpose === SsoLoginPurpose::ConnectionTest ? ModelStub::ulid('admin') : null,
            $purpose === SsoLoginPurpose::ConnectionTest ? 'https://nubos.test/nubos/engine/sso' : null,
        ))->toSession());
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Auth::forgetUser();
    Mockery::close();
});

it('offers the single sign-on entry only to guests, opens the callback to the signed in login test and throttles both', function (): void {
    expect(RouteShape::named('sso.redirect')->methods())->toContain('POST')
        ->and(RouteShape::named('sso.redirect')->uri())->toBe('auth/sso')
        ->and(RouteShape::named('sso.redirect')->hasDeclaredMiddleware('guest'))->toBeTrue()
        ->and(RouteShape::named('sso.redirect')->hasDeclaredMiddleware('throttle:sso'))->toBeTrue()
        ->and(RouteShape::named('sso.callback')->methods())->toContain('GET')
        ->and(RouteShape::named('sso.callback')->uri())->toBe('auth/sso/callback')
        ->and(RouteShape::named('sso.callback')->hasDeclaredMiddleware('guest'))->toBeFalse()
        ->and(RouteShape::named('sso.callback')->hasDeclaredMiddleware('auth'))->toBeFalse()
        ->and(RouteShape::named('sso.callback')->hasDeclaredMiddleware('throttle:sso'))->toBeTrue();
});

it('sends a signed in user who arrives with a pending normal sign-on away exactly like the guest middleware and leaves the sign-on untouched', function (): void {
    URL::defaults([TeamSegment::key() => 'nubos']);
    AccessContext::actAs(ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'status' => UserStatus::Accepted->value]));
    $request = ($this->request)('/auth/sso/callback?state=state-123&code=c');
    ($this->pendingIn)($request, SsoLoginPurpose::Login);
    $this->complete->shouldNotReceive('execute');
    $this->completeTest->shouldNotReceive('execute');
    $this->guard->shouldNotReceive('login');

    $response = app(SsoLoginController::class)->callback($request);

    expect($response->headers->get('Location'))->toBe(route('dashboard'))
        ->and($request->session()->has(OidcAuthorizationFlow::$sessionKey))->toBeTrue();
});

it('completes a pending login test for the signed in user, signs nobody in and returns to the settings with a success toast', function (): void {
    $admin = AccessContext::actAs(ModelStub::make(User::class, ['id' => ModelStub::ulid('admin'), 'status' => UserStatus::Accepted->value]));
    $request = ($this->request)('/auth/sso/callback?state=state-123&code=c');
    $request->setUserResolver(static fn (): User => $admin);
    ($this->pendingIn)($request, SsoLoginPurpose::ConnectionTest);
    $this->complete->shouldNotReceive('execute');
    $this->guard->shouldNotReceive('login');
    $this->loginResponse->shouldNotReceive('toResponse');
    $this->completeTest->shouldReceive('execute')->once()
        ->with(['state' => 'state-123', 'code' => 'c'], $request->session(), $admin)
        ->andReturn(new SsoConnection);

    $response = app(SsoLoginController::class)->callback($request);

    expect($response->headers->get('Location'))->toBe('https://nubos.test/nubos/engine/sso')
        ->and(session()->get(SessionKey::FLASH_DATA)['toast'])->toBe([
            'type' => ToastType::Success->value,
            'message' => 'Die Testanmeldung war erfolgreich: Client-ID, Client-Secret, Weiterleitungs-URL und die Anmeldung beim Identitätsanbieter sind bestätigt.',
        ]);
});

it('returns a refused login test to the settings with the german reason', function (): void {
    $request = ($this->request)('/auth/sso/callback?state=state-123&code=c');
    ($this->pendingIn)($request, SsoLoginPurpose::ConnectionTest);
    $this->completeTest->shouldReceive('execute')->once()->andThrow(new SsoLoginException(SsoFailure::ForeignInitiator));
    $this->guard->shouldNotReceive('login');

    $response = app(SsoLoginController::class)->callback($request);
    $toast = session()->get(SessionKey::FLASH_DATA)['toast'];

    expect($response->headers->get('Location'))->toBe('https://nubos.test/nubos/engine/sso')
        ->and($toast['type'])->toBe(ToastType::Error->value)
        ->and($toast['message'])->toStartWith('Die Testanmeldung ist fehlgeschlagen.')
        ->and($toast['message'])->toContain(SsoFailure::ForeignInitiator->label());
});

it('hands only the email to the start and leaves the page for the identity provider', function (): void {
    $request = ($this->request)('/auth/sso', 'POST', ['email' => 'ada@acme.de', 'tenant_id' => 'forged'], [Header::INERTIA => 'true']);
    $this->start->shouldReceive('execute')->once()->with(['email' => 'ada@acme.de'], $request->session())->andReturn('https://idp.acme.test/authorize?state=x');

    $response = app(SsoLoginController::class)->redirect($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get(Header::LOCATION))->toBe('https://idp.acme.test/authorize?state=x');
});

it('signs the resolved user in with a fresh session and answers like the password login', function (): void {
    $request = ($this->request)('/auth/sso/callback?state=s&code=c');
    $user = ModelStub::make(User::class, ['status' => UserStatus::Accepted->value]);
    $oldSessionId = $request->session()->getId();
    $this->complete->shouldReceive('execute')->once()->with(['state' => 's', 'code' => 'c'], $request->session())->andReturn($user);
    $this->guard->shouldReceive('login')->once()->with($user);
    $this->loginResponse->shouldReceive('toResponse')->once()->with($request)->andReturn(new RedirectResponse('/acme/dashboard'));

    $response = app(SsoLoginController::class)->callback($request);

    expect($response->headers->get('Location'))->toBe('/acme/dashboard')
        ->and($request->session()->getId())->not->toBe($oldSessionId);
});

it('sends a refused single sign-on back to the login page with the german reason and signs nobody in', function (): void {
    $request = ($this->request)('/auth/sso/callback?state=s');
    $this->complete->shouldReceive('execute')->andThrow(new SsoLoginException(SsoFailure::ForeignAccount));
    $this->guard->shouldNotReceive('login');

    $response = app(SsoLoginController::class)->callback($request);

    expect($response->headers->get('Location'))->toBe(route('login'))
        ->and($request->session()->get('ssoError'))->toBe(SsoFailure::ForeignAccount->label())
        ->and($request->session()->get('ssoError'))->toStartWith('Für diese Anmeldung gibt es kein Konto');
});

it('shows the reason of a refused single sign-on on the login page', function (): void {
    $request = ($this->request)('/login');
    $request->session()->put('ssoError', 'Grund');

    $props = app(LoginViewResponse::class)->toResponse($request)->original->getData()['page']['props'];

    expect($props['ssoError'])->toBe('Grund');
});

it('refuses the correct password of a member whose organisation enforces single sign-on on the email field', function (bool $isBlocked): void {
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"')
        ? [['id' => ModelStub::ulid('ada'), 'tenant_id' => ModelStub::ulid('acme'), 'email' => 'ada@acme.de', 'status' => UserStatus::Accepted->value, 'is_service' => false, 'password' => Hash::make('geheim')]]
        : []);
    $gate = Mockery::mock(SsoEnforcementGate::class);
    $gate->shouldReceive('blocksLocalLogin')->once()->andReturn($isBlocked);
    app()->instance(SsoEnforcementGate::class, $gate);

    $request = ($this->request)('/login', 'POST', ['email' => 'ada@acme.de', 'password' => 'geheim']);

    try {
        $user = (Fortify::$authenticateUsingCallback)($request);
    } catch (ValidationException $exception) {
        expect($isBlocked)->toBeTrue()
            ->and($exception->errors())->toBe(['email' => [SsoFailure::LocalLoginEnforced->label()]]);

        return;
    }

    expect($isBlocked)->toBeFalse()
        ->and($user)->toBeInstanceOf(User::class);
})->with(['enforced' => [true], 'emergency access or not enforced' => [false]]);

it('asks the single sign-on gate nothing while the password is wrong', function (): void {
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"')
        ? [['id' => ModelStub::ulid('ada'), 'tenant_id' => ModelStub::ulid('acme'), 'email' => 'ada@acme.de', 'status' => UserStatus::Accepted->value, 'is_service' => false, 'password' => Hash::make('geheim')]]
        : []);
    $gate = Mockery::mock(SsoEnforcementGate::class);
    $gate->shouldNotReceive('blocksLocalLogin');
    app()->instance(SsoEnforcementGate::class, $gate);

    expect((Fortify::$authenticateUsingCallback)(($this->request)('/login', 'POST', ['email' => 'ada@acme.de', 'password' => 'falsch'])))->toBeNull();
});

it('refuses the passkey of a member whose organisation enforces single sign-on while the emergency access keeps it', function (bool $isBlocked): void {
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'tenant_id' => ModelStub::ulid('acme'), 'email' => 'ada@acme.de', 'status' => UserStatus::Accepted->value, 'is_service' => false]);
    $passkey = ModelStub::make(Passkey::class, ['id' => ModelStub::ulid('ada-key')], ['user' => $user]);
    $gate = Mockery::mock(SsoEnforcementGate::class);
    $gate->shouldReceive('blocksLocalLogin')->once()->with($user)->andReturn($isBlocked);
    app()->instance(SsoEnforcementGate::class, $gate);

    try {
        $isAllowed = Passkeys::allowsLogin(($this->request)('/passkeys/login', 'POST'), $passkey);
    } catch (ValidationException $exception) {
        expect($isBlocked)->toBeTrue()
            ->and($exception->errors())->toBe(['credential' => [SsoFailure::LocalLoginEnforced->label()]]);

        return;
    }

    expect($isBlocked)->toBeFalse()
        ->and($isAllowed)->toBeTrue();
})->with(['enforced' => [true], 'emergency access or not enforced' => [false]]);

it('asks the single sign-on gate nothing for the passkey of a blocked account', function (): void {
    $user = ModelStub::make(User::class, ['id' => ModelStub::ulid('ada'), 'tenant_id' => ModelStub::ulid('acme'), 'email' => 'ada@acme.de', 'status' => UserStatus::Blocked->value, 'is_service' => false]);
    $passkey = ModelStub::make(Passkey::class, ['id' => ModelStub::ulid('ada-key')], ['user' => $user]);
    $gate = Mockery::mock(SsoEnforcementGate::class);
    $gate->shouldNotReceive('blocksLocalLogin');
    app()->instance(SsoEnforcementGate::class, $gate);

    expect(Passkeys::allowsLogin(($this->request)('/passkeys/login', 'POST'), $passkey))->toBeFalse();
});
