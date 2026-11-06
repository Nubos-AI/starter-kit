<?php

declare(strict_types=1);

use App\Actions\Sso\CompleteSsoLoginTestAction;
use App\Actions\Sso\ResolveSsoUserAction;
use App\Actions\Sso\StartSsoLoginTestAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\IdTokenExpectation;
use App\DTOs\Sso\OidcProviderMetadata;
use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoFailure;
use App\Enums\Sso\SsoLoginProofStatus;
use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\IdTokenRejectedException;
use App\Exceptions\Sso\OidcProviderException;
use App\Exceptions\Sso\SsoLoginException;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\IdTokenVerifier;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Sso\OidcProviderClient;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Teams\TeamSegment;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));
    config(['sso.oidc.pending_login_ttl_seconds' => 600]);
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('acme');
    $this->admin = AccessContext::actAs(AccessContext::user($this->tenant, ['email' => 'admin@acme.de'], 'admin'));
    $this->attributes = [
        'id' => ModelStub::ulid('connection'),
        'tenant_id' => $this->tenant->getKey(),
        'is_enabled' => true,
        'is_enforced' => false,
        'issuer_url' => 'https://idp.acme.test',
        'client_id' => 'client-acme',
        'client_secret' => encrypt('client-secret', false),
        'allowed_domains' => '["acme.de"]',
        'verified_domains' => '["acme.de"]',
        'login_verified_at' => null,
        'login_verified_fingerprint' => null,
    ];
    $this->connection = ModelStub::make(SsoConnection::class, $this->attributes);
    $this->metadata = new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/authorize', 'https://idp.acme.test/token', 'https://idp.acme.test/keys', ['client_secret_basic']);

    $this->database = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $this->session = new Store('test', new ArraySessionHandler(60));
    $this->lookup = Mockery::mock(SsoConnectionLookup::class);
    $this->provider = Mockery::mock(OidcProviderClient::class);
    $this->verifier = Mockery::mock(IdTokenVerifier::class);
    $this->resolver = Mockery::mock(ResolveSsoUserAction::class);
    $this->resolver->shouldNotReceive('execute');
    app()->instance(SsoConnectionLookup::class, $this->lookup);
    app()->instance(OidcProviderClient::class, $this->provider);
    app()->instance(IdTokenVerifier::class, $this->verifier);
    app()->instance(ResolveSsoUserAction::class, $this->resolver);

    $this->pending = function (array $overrides = []): void {
        $pending = new PendingSsoLogin(
            (string) $this->connection->getKey(),
            $overrides['tenant_id'] ?? (string) $this->tenant->getKey(),
            'state-123',
            'nonce-456',
            'verifier-789',
            $overrides['issued_at'] ?? Carbon::now()->getTimestamp(),
            $overrides['purpose'] ?? SsoLoginPurpose::ConnectionTest,
            $overrides['initiator_id'] ?? (string) $this->admin->getKey(),
            'https://nubos.test/nubos/engine/sso',
        );

        $this->session->put(OidcAuthorizationFlow::$sessionKey, $pending->toSession());
    };

    $this->failureOf = function (array $query, ?User $actingUser): ?SsoFailure {
        try {
            app(CompleteSsoLoginTestAction::class)->execute($query, $this->session, $actingUser);
        } catch (SsoLoginException $exception) {
            return $exception->failure;
        }

        return null;
    };

    $this->connectionWrites = fn (): array => $this->database->writtenSqlOf('sso_connections');
});

afterEach(function (): void {
    Carbon::setTestNow();
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a login test while no enabled and complete connection is saved on the login test field', function (?array $overrides): void {
    $this->lookup->shouldReceive('forTenant')->with((string) $this->tenant->getKey())->andReturn(
        $overrides === null ? null : ModelStub::make(SsoConnection::class, [...$this->attributes, ...$overrides]),
    );
    $this->provider->shouldNotReceive('metadata');

    expect(fn () => app(StartSsoLoginTestAction::class)->execute($this->admin, $this->session))
        ->toThrow(ValidationException::class, __('i18n.backend.actions.sso.start_sso_login_test_action.connection_incomplete'))
        ->and($this->session->has(OidcAuthorizationFlow::$sessionKey))->toBeFalse();
})->with([
    'nothing saved' => [null],
    'disabled' => [['is_enabled' => false]],
    'no client id' => [['client_id' => null]],
    'no client secret' => [['client_secret' => null]],
]);

it('reports a failing discovery of a login test on the login test field', function (): void {
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andThrow(OidcProviderException::unexpectedAnswer());

    try {
        app(StartSsoLoginTestAction::class)->execute($this->admin, $this->session);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['login_test' => [OidcProviderException::unexpectedAnswer()->userMessage()]]);

        return;
    }

    $this->fail('The login test started.');
});

it('starts a login test as a real authorization code flow with pkce against the registered callback and remembers who started it', function (): void {
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->with('https://idp.acme.test')->andReturn($this->metadata);

    $url = app(StartSsoLoginTestAction::class)->execute($this->admin, $this->session);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $pending = PendingSsoLogin::fromSession($this->session->get(OidcAuthorizationFlow::$sessionKey));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $pending->codeVerifier, true)), '+/', '-_'), '=');

    expect(str_starts_with($url, 'https://idp.acme.test/authorize?'))->toBeTrue()
        ->and($query)->toBe([
            'response_type' => 'code',
            'client_id' => 'client-acme',
            'redirect_uri' => route('sso.callback'),
            'scope' => 'openid email profile',
            'state' => $pending->state,
            'nonce' => $pending->nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])
        ->and($pending->purpose)->toBe(SsoLoginPurpose::ConnectionTest)
        ->and($pending->initiatorId)->toBe((string) $this->admin->getKey())
        ->and($pending->tenantId)->toBe((string) $this->tenant->getKey())
        ->and($pending->returnUrl)->toBe(route('engine.sso.edit'));
});

it('refuses a login test callback without a pending test, with a foreign state, after it expired or for a pending normal login', function (?array $pending, string $state): void {
    if ($pending !== null) {
        ($this->pending)($pending);
    }

    $this->provider->shouldNotReceive('exchangeCode');

    expect(($this->failureOf)(['state' => $state, 'code' => 'code-1'], $this->admin))->toBe(SsoFailure::SessionExpired)
        ->and($this->session->has(OidcAuthorizationFlow::$sessionKey))->toBeFalse()
        ->and(($this->connectionWrites)())->toBe([]);
})->with([
    'nothing pending' => [null, 'state-123'],
    'foreign state' => [[], 'state-forged'],
    'expired' => [['issued_at' => Carbon::parse('2026-10-30 11:49:59', 'UTC')->getTimestamp()], 'state-123'],
    'pending normal login' => [['purpose' => SsoLoginPurpose::Login], 'state-123'],
]);

it('refuses a login test callback for anyone but the signed in user of the tenant that started it', function (string $case): void {
    $actingUser = match ($case) {
        'guest' => null,
        'another user' => AccessContext::user($this->tenant, [], 'someone-else'),
        'other tenant' => AccessContext::user(AccessContext::tenant('globex'), [], 'admin'),
    };

    if ($case === 'other tenant') {
        AccessContext::tenant('acme');
    }

    ($this->pending)();
    $this->lookup->shouldNotReceive('forTenant');
    $this->provider->shouldNotReceive('exchangeCode');

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1'], $actingUser))->toBe(SsoFailure::ForeignInitiator)
        ->and(($this->connectionWrites)())->toBe([]);
})->with(['guest', 'another user', 'other tenant']);

it('records no proof when the identity provider refuses the client credentials at the token endpoint', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->once()->andThrow(OidcProviderException::unexpectedAnswer());
    $this->verifier->shouldNotReceive('verify');

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1'], $this->admin))->toBe(SsoFailure::ProviderUnavailable)
        ->and(($this->connectionWrites)())->toBe([]);
});

it('records no proof for an id token the verifier rejects', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->andReturn('id.token.value');
    $this->provider->shouldReceive('signingKeys')->andReturn([]);
    $this->verifier->shouldReceive('verify')->andThrow(IdTokenRejectedException::foreignAudience());

    expect(($this->failureOf)(['state' => 'state-123', 'code' => 'code-1'], $this->admin))->toBe(SsoFailure::TokenRejected)
        ->and(($this->connectionWrites)())->toBe([]);
});

it('proves the login with the stored credentials and the registered callback without signing anybody in or provisioning anyone', function (): void {
    ($this->pending)();
    $this->lookup->shouldReceive('forTenant')->with((string) $this->tenant->getKey())->andReturn($this->connection);
    $this->provider->shouldReceive('metadata')->andReturn($this->metadata);
    $this->provider->shouldReceive('exchangeCode')->once()
        ->with($this->metadata, 'client-acme', 'client-secret', 'code-1', route('sso.callback'), 'verifier-789')
        ->andReturn('id.token.value');
    $this->provider->shouldReceive('signingKeys')->once()->with($this->metadata)->andReturn([['kid' => 'one']]);
    $this->verifier->shouldReceive('verify')->once()
        ->withArgs(fn (string $token, IdTokenExpectation $expected): bool => $token === 'id.token.value'
            && $expected->issuer === 'https://idp.acme.test'
            && $expected->clientId === 'client-acme'
            && $expected->nonce === 'nonce-456')
        ->andReturn(new IdTokenClaims('https://idp.acme.test', 'subject-1', 'someone@acme.de', true, null, null));

    $proven = app(CompleteSsoLoginTestAction::class)->execute(['state' => 'state-123', 'code' => 'code-1'], $this->session, $this->admin);
    $update = collect($this->database->writtenStatements)->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "sso_connections"'));

    expect($proven->hasCurrentLoginProof())->toBeTrue()
        ->and($proven->login_verified_at?->toIso8601String())->toBe('2026-10-30T12:00:00+00:00')
        ->and($update['sql'])->toContain('"login_verified_at" = ?')
        ->and($update['bindings'])->toContain($proven->loginFingerprint())
        ->and(json_encode($this->database->writtenStatements))->not->toContain('client-secret')
        ->and(json_encode($this->database->writtenStatements))->not->toContain('code-1')
        ->and($this->database->writtenSqlOf('users'))->toBe([])
        ->and($this->database->writtenSqlOf('user_identities'))->toBe([])
        ->and(Auth::user())->toBe($this->admin)
        ->and($this->session->has(OidcAuthorizationFlow::$sessionKey))->toBeFalse();
});

it('keeps a login proof current only while issuer, client id and client secret stay the proven ones', function (array $changes, SsoLoginProofStatus $status): void {
    $proven = ModelStub::make(SsoConnection::class, $this->attributes);
    $fingerprint = $proven->loginFingerprint();

    $connection = ModelStub::make(SsoConnection::class, [
        ...$this->attributes,
        'login_verified_at' => '2026-10-30 11:00:00',
        'login_verified_fingerprint' => $fingerprint,
        ...$changes,
        ...(isset($changes['client_secret']) ? ['client_secret' => encrypt($changes['client_secret'], false)] : []),
    ]);

    expect($connection->loginProofStatus())->toBe($status)
        ->and($connection->hasCurrentLoginProof())->toBe($status === SsoLoginProofStatus::Verified)
        ->and($fingerprint)->not->toContain('client-secret')
        ->and(strlen($fingerprint))->toBe(64);
})->with([
    'unchanged' => [[], SsoLoginProofStatus::Verified],
    'other issuer' => [['issuer_url' => 'https://idp.other.test'], SsoLoginProofStatus::Outdated],
    'other client id' => [['client_id' => 'client-other'], SsoLoginProofStatus::Outdated],
    'other client secret' => [['client_secret' => 'rotated-secret'], SsoLoginProofStatus::Outdated],
    'never proven' => [['login_verified_at' => null, 'login_verified_fingerprint' => null], SsoLoginProofStatus::Missing],
]);
