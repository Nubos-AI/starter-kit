<?php

declare(strict_types=1);

use App\DTOs\Sso\IdTokenExpectation;
use App\Exceptions\Sso\IdTokenRejectedException;
use App\Support\Sso\IdTokenVerifier;
use App\Support\Sso\JwkPublicKeyConverter;
use Illuminate\Contracts\Config\Repository;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->base64Url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $this->rsaKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $details = openssl_pkey_get_details($this->rsaKey);

    $this->jwks = [[
        'kty' => 'RSA',
        'kid' => 'rsa-1',
        'use' => 'sig',
        'n' => ($this->base64Url)($details['rsa']['n']),
        'e' => ($this->base64Url)($details['rsa']['e']),
    ]];

    $this->claims = static fn (array $overrides = []): array => [
        'iss' => 'https://idp.acme.test',
        'sub' => 'subject-4711',
        'aud' => 'client-acme',
        'exp' => time() + 300,
        'iat' => time(),
        'nonce' => 'nonce-abc',
        'email' => 'ada@acme.de',
        'email_verified' => true,
        ...$overrides,
    ];

    $this->token = function (array $claims, array $header = ['alg' => 'RS256', 'kid' => 'rsa-1']): string {
        $signingInput = ($this->base64Url)(json_encode($header)).'.'.($this->base64Url)(json_encode($claims));

        openssl_sign($signingInput, $signature, $this->rsaKey, OPENSSL_ALGO_SHA256);

        return "{$signingInput}.".($this->base64Url)($signature);
    };

    $this->config = Mockery::mock(Repository::class);
    $this->config->shouldReceive('get')->with('sso.oidc.clock_leeway_seconds')->andReturn(60);

    $this->verifier = new IdTokenVerifier(new JwkPublicKeyConverter, $this->config);
    $this->expectation = new IdTokenExpectation('https://idp.acme.test', 'client-acme', 'nonce-abc');

    $this->rejectionOf = function (string $token): string {
        try {
            $this->verifier->verify($token, $this->expectation, $this->jwks);
        } catch (IdTokenRejectedException $exception) {
            return $exception->getMessage();
        }

        return 'accepted';
    };
});

it('accepts an RS256 signed id token of the expected issuer, audience and nonce', function (): void {
    $claims = $this->verifier->verify(($this->token)(($this->claims)()), $this->expectation, $this->jwks);

    expect($claims->subject)->toBe('subject-4711')
        ->and($claims->isEmailVerified)->toBeTrue();

    $this->config->shouldHaveReceived('get')->with('sso.oidc.clock_leeway_seconds');
});

it('rejects a token whose payload was changed after signing', function (): void {
    [$header, , $signature] = explode('.', ($this->token)(($this->claims)()));
    $forged = ($this->base64Url)(json_encode(($this->claims)(['sub' => 'admin'])));

    expect(($this->rejectionOf)("{$header}.{$forged}.{$signature}"))->toBe(IdTokenRejectedException::invalidSignature()->getMessage());
});

it('rejects the unsigned and the symmetric algorithms', function (string $algorithm): void {
    $header = ($this->base64Url)(json_encode(['alg' => $algorithm, 'kid' => 'rsa-1']));
    $payload = ($this->base64Url)(json_encode(($this->claims)()));
    $signature = $algorithm === 'none' ? '' : ($this->base64Url)(hash_hmac('sha256', "{$header}.{$payload}", 'client-secret', true));

    expect(($this->rejectionOf)("{$header}.{$payload}.{$signature}"))->toBe(IdTokenRejectedException::algorithmNotAccepted()->getMessage());
})->with(['none', 'HS256']);

it('rejects claims the provider was not expected to issue', function (array $overrides): void {
    expect(($this->rejectionOf)(($this->token)(($this->claims)($overrides))))->not->toBe('accepted');
})->with([
    'foreign issuer' => [['iss' => 'https://evil.test']],
    'foreign audience' => [['aud' => 'client-other']],
    'expired beyond the leeway' => [['exp' => time() - 600]],
    'issued in the future beyond the leeway' => [['iat' => time() + 600]],
    'replayed nonce' => [['nonce' => 'nonce-replayed']],
]);
