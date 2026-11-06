<?php

declare(strict_types=1);

use App\DTOs\Sso\OidcProviderMetadata;
use App\Exceptions\Sso\OidcProviderException;
use App\Support\Sso\OidcProviderClient;
use App\Support\Webhooks\SsrfGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();

    $this->resolvingTo = static function (array $resolveMap): void {
        app()->instance(SsrfGuard::class, new SsrfGuard(
            static fn (string $host): array => $resolveMap[$host] ?? ['93.184.216.34'],
        ));
    };

    ($this->resolvingTo)([]);

    $this->document = static fn (array $overrides = []): array => [
        'issuer' => 'https://idp.acme.test',
        'authorization_endpoint' => 'https://idp.acme.test/authorize',
        'token_endpoint' => 'https://idp.acme.test/token',
        'jwks_uri' => 'https://idp.acme.test/keys',
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ...$overrides,
    ];

    $this->metadata = new OidcProviderMetadata(
        'https://idp.acme.test',
        'https://idp.acme.test/authorize',
        'https://idp.acme.test/token',
        'https://idp.acme.test/keys',
        ['client_secret_basic'],
    );

    $this->failureOf = static function (Closure $call): string {
        try {
            $call();
        } catch (OidcProviderException $exception) {
            return $exception->getMessage();
        }

        return 'succeeded';
    };
});

it('reads the provider metadata from the well known discovery document and caches it', function (): void {
    Http::fake(['idp.acme.test/.well-known/openid-configuration' => Http::response(($this->document)())]);

    $first = app(OidcProviderClient::class)->metadata('https://idp.acme.test/');
    $second = app(OidcProviderClient::class)->metadata('https://idp.acme.test');

    expect($first->issuer)->toBe('https://idp.acme.test')
        ->and($first->authorizationEndpoint)->toBe('https://idp.acme.test/authorize')
        ->and($first->tokenEndpoint)->toBe('https://idp.acme.test/token')
        ->and($first->jwksUri)->toBe('https://idp.acme.test/keys')
        ->and($second)->toEqual($first);

    Http::assertSentCount(1);
});

it('refuses an issuer that resolves to a private, loopback or metadata address before any request leaves', function (string $ip): void {
    ($this->resolvingTo)(['idp.acme.test' => [$ip]]);
    Http::fake();

    expect(($this->failureOf)(fn () => app(OidcProviderClient::class)->metadata('https://idp.acme.test')))
        ->toBe('The identity provider address is not allowed.');

    Http::assertNothingSent();
})->with(['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.10']);

it('refuses an issuer that is not reached over https', function (): void {
    Http::fake();

    expect(($this->failureOf)(fn () => app(OidcProviderClient::class)->metadata('http://idp.acme.test')))
        ->toBe('The identity provider must be reached over https.');

    Http::assertNothingSent();
});

it('refuses a discovery document that names another issuer or an endpoint outside https', function (array $overrides): void {
    Http::fake(['idp.acme.test/*' => Http::response(($this->document)($overrides))]);

    expect(($this->failureOf)(fn () => app(OidcProviderClient::class)->metadata('https://idp.acme.test')))
        ->toBe('The discovery document of the identity provider is invalid.');
})->with([
    'other issuer' => [['issuer' => 'https://evil.test']],
    'plain http token endpoint' => [['token_endpoint' => 'http://idp.acme.test/token']],
    'missing jwks uri' => [['jwks_uri' => null]],
]);

it('accepts an address that differs from the issuer of the discovery document only by a trailing slash and hands back the documented issuer', function (string $entered, string $documented): void {
    Http::fake(['idp.acme.test/.well-known/openid-configuration' => Http::response(($this->document)(['issuer' => $documented]))]);

    expect(app(OidcProviderClient::class)->metadata($entered)->issuer)->toBe($documented);
})->with([
    'entered with slash' => ['https://idp.acme.test/', 'https://idp.acme.test'],
    'documented with slash' => ['https://idp.acme.test', 'https://idp.acme.test/'],
]);

it('accepts the tenant specific issuer of microsoft entra', function (): void {
    $issuer = 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0';
    Http::fake(['login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0/.well-known/openid-configuration' => Http::response(($this->document)([
        'issuer' => $issuer,
        'authorization_endpoint' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/oauth2/v2.0/authorize',
        'token_endpoint' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/oauth2/v2.0/token',
        'jwks_uri' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/discovery/v2.0/keys',
    ]))]);

    expect(app(OidcProviderClient::class)->metadata("{$issuer}/")->issuer)->toBe($issuer);
});

it('refuses the multi tenant entra endpoints whose discovery document names only a tenant placeholder', function (string $segment): void {
    Http::fake(["login.microsoftonline.com/{$segment}/v2.0/.well-known/openid-configuration" => Http::response(($this->document)([
        'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
        'authorization_endpoint' => "https://login.microsoftonline.com/{$segment}/oauth2/v2.0/authorize",
        'token_endpoint' => "https://login.microsoftonline.com/{$segment}/oauth2/v2.0/token",
        'jwks_uri' => "https://login.microsoftonline.com/{$segment}/discovery/v2.0/keys",
    ]))]);

    try {
        app(OidcProviderClient::class)->metadata("https://login.microsoftonline.com/{$segment}/v2.0");
    } catch (OidcProviderException $exception) {
        expect($exception->reason)->toBe('multi_tenant_issuer');

        return;
    }

    $this->fail('The multi tenant issuer was accepted.');
})->with(['common', 'organizations']);

it('reports an unreachable provider', function (): void {
    Http::fake(['idp.acme.test/*' => Http::response('down', 503)]);

    expect(($this->failureOf)(fn () => app(OidcProviderClient::class)->metadata('https://idp.acme.test')))
        ->toBe('The identity provider did not answer as expected.');
});

it('caches the signing keys and fetches them anew on request', function (): void {
    Http::fakeSequence('idp.acme.test/keys')
        ->push(['keys' => [['kty' => 'RSA', 'kid' => 'one']]])
        ->push(['keys' => [['kty' => 'RSA', 'kid' => 'two']]]);

    $client = app(OidcProviderClient::class);

    expect($client->signingKeys($this->metadata)[0]['kid'])->toBe('one')
        ->and($client->signingKeys($this->metadata)[0]['kid'])->toBe('one')
        ->and($client->signingKeys($this->metadata, true)[0]['kid'])->toBe('two');

    Http::assertSentCount(2);
});

it('exchanges the authorization code with the client credentials and the pkce verifier', function (): void {
    Http::fake(['idp.acme.test/token' => Http::response(['id_token' => 'header.payload.signature', 'access_token' => 'opaque'])]);

    $idToken = app(OidcProviderClient::class)->exchangeCode($this->metadata, 'client-acme', 'client-secret', 'code-123', 'https://nubos.test/auth/sso/callback', 'verifier-xyz');

    expect($idToken)->toBe('header.payload.signature');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://idp.acme.test/token'
        && $request->method() === 'POST'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'code-123'
        && $request['code_verifier'] === 'verifier-xyz'
        && $request['redirect_uri'] === 'https://nubos.test/auth/sso/callback'
        && !isset($request['client_secret'])
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('client-acme:client-secret'));
});

it('sends the client secret in the body when the provider only accepts that', function (): void {
    Http::fake(['idp.acme.test/token' => Http::response(['id_token' => 'a.b.c'])]);
    $metadata = new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/authorize', 'https://idp.acme.test/token', 'https://idp.acme.test/keys', ['client_secret_post']);

    app(OidcProviderClient::class)->exchangeCode($metadata, 'client-acme', 'client-secret', 'code-123', 'https://nubos.test/auth/sso/callback', 'verifier-xyz');

    Http::assertSent(static fn (Request $request): bool => $request['client_id'] === 'client-acme' && $request['client_secret'] === 'client-secret');
});

it('reports a refused code exchange', function (): void {
    Http::fake(['idp.acme.test/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(($this->failureOf)(fn () => app(OidcProviderClient::class)->exchangeCode($this->metadata, 'client-acme', 'secret', 'code', 'https://nubos.test/cb', 'verifier')))
        ->toBe('The identity provider did not answer as expected.');
});
