<?php

declare(strict_types=1);

use App\Actions\Sso\CompleteSsoLoginAction;
use App\Actions\Sso\ResolveSsoUserAction;
use App\Actions\Sso\StartSsoLoginAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\IdTokenExpectation;
use App\DTOs\Sso\OidcProviderMetadata;
use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\IdTokenRejectedException;
use App\Exceptions\Sso\OidcProviderException;
use App\Exceptions\Sso\SsoLoginException;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\User;
use App\Support\Sso\IdTokenVerifier;
use App\Support\Sso\OidcProviderClient;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));
    config(['sso.oidc.pending_login_ttl_seconds' => 600]);

    $this->tenant = AccessContext::tenant('acme');
    $this->connection = ModelStub::make(SsoConnection::class, [
        'id' => ModelStub::ulid('connection'),
        'tenant_id' => $this->tenant->getKey(),
        'is_enabled' => true,
        'issuer_url' => 'https://idp.acme.test',
        'client_id' => 'client-acme',
        'client_secret' => encrypt('client-secret', false),
        'allowed_domains' => ['acme.de'],
    ], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de']])]);
    $this->metadata = new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/authorize?tenant=acme', 'https://idp.acme.test/token', 'https://idp.acme.test/keys', ['client_secret_basic']);

    $this->session = new Store('test', new ArraySessionHandler(60));
    $this->lookup = Mockery::mock(SsoConnectionLookup::class);
    $this->provider = Mockery::mock(OidcProviderClient::class);
    $this->verifier = Mockery::mock(IdTokenVerifier::class);
    $this->resolver = Mockery::mock(ResolveSsoUserAction::class);
    app()->instance(SsoConnectionLookup::class, $this->lookup);
    app()->instance(OidcProviderClient::class, $this->provider);
    app()->instance(IdTokenVerifier::class, $this->verifier);
    app()->instance(ResolveSsoUserAction::class, $this->resolver);

    $this->pending = function (array $overrides = []): void {
        $pending = new PendingSsoLogin(
            $overrides['connection_id'] ?? (string) $this->connection->getKey(),
            (string) $this->tenant->getKey(),
            'state-123',
            'nonce-456',
            'verifier-789',
            $overrides['issued_at'] ?? Carbon::now()->getTimestamp(),
        );

        $this->session->put('sso.pending', $pending->toSession());
    };

    $this->failureOf = function (array $query): ?SsoFailure {
        try {
            app(CompleteSsoLoginAction::class)->execute($query, $this->session);
        } catch (SsoLoginException $exception) {
            return $exception->failure;
        }

        return null;
    };

    $this->claims = new IdTokenClaims('https://idp.acme.test', 'subject-1', 'ada@acme.de', true, 'Ada', 'Lovelace');
});

afterEach(function (): void {
    Carbon::setTestNow();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to start a single sign-on for a malformed address on the email field', function (): void {
    $this->lookup->shouldNotReceive('enabledForDomain');

    expect(fn () => app(StartSsoLoginAction::class)->execute(['email' => 'no-address'], $this->session))
        ->toThrow(ValidationException::class);
});

it('refuses a domain no enabled connection claims on the email field', function (): void {
    $this->lookup->shouldReceive('enabledForDomain')->once()->with('partner.de')->andReturnNull();

    try {
        app(StartSsoLoginAction::class)->execute(['email' => 'Ada@Partner.de'], $this->session);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => [SsoFailure::NoConnection->label()]])
            ->and($this->session->has('sso.pending'))->toBeFalse();

        return;
    }

    $this->fail('The single sign-on started.');
});

it('reports an unreachable identity provider on the email field', function (): void {
    $this->lookup->shouldReceive('enabledForDomain')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andThrow(OidcProviderException::unexpectedAnswer());

    try {
        app(StartSsoLoginAction::class)->execute(['email' => 'ada@acme.de'], $this->session);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => [SsoFailure::ProviderUnavailable->label()]]);

        return;
    }

    $this->fail('The single sign-on started.');
});

it('sends the user to the identity provider with state, nonce and an S256 pkce challenge it keeps in the session', function (): void {
    $this->lookup->shouldReceive('enabledForDomain')->with('acme.de')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->with('https://idp.acme.test')->andReturn($this->metadata);

    $url = app(StartSsoLoginAction::class)->execute(['email' => 'ada@acme.de'], $this->session);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $pending = PendingSsoLogin::fromSession($this->session->get('sso.pending'));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $pending->codeVerifier, true)), '+/', '-_'), '=');

    expect(str_starts_with($url, 'https://idp.acme.test/authorize?tenant=acme&'))->toBeTrue()
        ->and($query)->toMatchArray([
            'tenant' => 'acme',
            'response_type' => 'code',
            'client_id' => 'client-acme',
            'redirect_uri' => route('sso.callback'),
            'scope' => 'openid email profile',
            'state' => $pending->state,
            'nonce' => $pending->nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'login_hint' => 'ada@acme.de',
        ])
        ->and($pending->connectionId)->toBe((string) $this->connection->getKey())
        ->and($pending->tenantId)->toBe((string) $this->tenant->getKey())
        ->and(strlen($pending->state))->toBeGreaterThanOrEqual(40)
        ->and(strlen($pending->codeVerifier))->toBeGreaterThanOrEqual(43);
});

it('refuses a callback without a pending sign-on, with a foreign state or after the pending sign-on expired and consumes the pending sign-on once', function (?array $pending, string $state): void {
    if ($pending !== null) {
        ($this->pending)($pending);
    }

    $this->provider->shouldNotReceive('exchangeCode');

    expect(($this->failureOf)(['state' => $state, 'code' => 'code-1']))->toBe(SsoFailure::SessionExpired)
        ->and($this->session->has('sso.pending'))->toBeFalse();
})->with([
    'nothing pending' => [null, 'state-123'],
    'foreign state' => [[], 'state-forged'],
    'expired' => [['issued_at' => Carbon::parse('2026-10-30 11:49:59', 'UTC')->getTimestamp()], 'state-123'],
]);

it('reports a sign-on the identity provider declined', function (): void {
    ($this->pending)();

    expect(($this->failureOf)(['state' => 'state-123', 'error' => 'access_denied']))->toBe(SsoFailure::ProviderDenied);
});

it('refuses a callback for a connection that was disabled in the meantime', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->with((string) $this->tenant->getKey())->andReturn(
        ModelStub::make(SsoConnection::class, [...$this->connection->getAttributes(), 'is_enabled' => false]),
    );

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1']))->toBe(SsoFailure::NoConnection);
});

it('exchanges the code with the kept verifier, checks the id token against the kept nonce and resolves the user inside the tenant', function (): void {
    ($this->pending)();
    $user = ModelStub::make(User::class, ['tenant_id' => $this->tenant->getKey(), 'status' => UserStatus::Accepted->value]);

    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->once()
        ->with($this->metadata, 'client-acme', 'client-secret', 'code-1', route('sso.callback'), 'verifier-789')
        ->andReturn('id.token.value');
    $this->provider->shouldReceive('signingKeys')->once()->with($this->metadata)->andReturn([['kid' => 'one']]);
    $this->verifier->shouldReceive('verify')->once()
        ->withArgs(fn (string $token, IdTokenExpectation $expected, array $keys): bool => $token === 'id.token.value'
            && $expected->issuer === 'https://idp.acme.test'
            && $expected->clientId === 'client-acme'
            && $expected->nonce === 'nonce-456'
            && $keys === [['kid' => 'one']])
        ->andReturn($this->claims);
    $this->resolver->shouldReceive('execute')->once()
        ->withArgs(fn (SsoConnection $connection, IdTokenClaims $claims): bool => $connection->is($this->connection)
            && $claims === $this->claims
            && app('current_tenant')->is($this->tenant))
        ->andReturn($user);

    expect(app(CompleteSsoLoginAction::class)->execute(['state' => 'state-123', 'code' => 'code-1'], $this->session))->toBe($user)
        ->and($this->session->has('sso.pending'))->toBeFalse();
});

it('fetches the signing keys anew once when the id token names a key the cache does not know yet', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->andReturn('id.token.value');
    $this->provider->shouldReceive('signingKeys')->once()->with($this->metadata)->andReturn([['kid' => 'old']]);
    $this->provider->shouldReceive('signingKeys')->once()->with($this->metadata, true)->andReturn([['kid' => 'new']]);
    $this->verifier->shouldReceive('verify')->once()->with('id.token.value', Mockery::any(), [['kid' => 'old']])->andThrow(IdTokenRejectedException::unknownKey());
    $this->verifier->shouldReceive('verify')->once()->with('id.token.value', Mockery::any(), [['kid' => 'new']])->andReturn($this->claims);
    $this->resolver->shouldReceive('execute')->andReturn(ModelStub::make(User::class, ['status' => UserStatus::Accepted->value]));

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1']))->toBeNull();
});

it('refuses an id token the verifier rejects', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->andReturn('id.token.value');
    $this->provider->shouldReceive('signingKeys')->andReturn([]);
    $this->verifier->shouldReceive('verify')->andThrow(IdTokenRejectedException::foreignNonce());
    $this->resolver->shouldNotReceive('execute');

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1']))->toBe(SsoFailure::TokenRejected);
});

it('reports an identity provider that fails during the code exchange', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->andThrow(OidcProviderException::unexpectedAnswer());

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1']))->toBe(SsoFailure::ProviderUnavailable);
});
