<?php

declare(strict_types=1);

use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\IdTokenExpectation;
use App\Exceptions\Sso\IdTokenRejectedException;
use App\Support\Sso\IdTokenVerifier;
use Illuminate\Support\Carbon;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));
    config(['sso.oidc.clock_leeway_seconds' => 60]);

    $this->base64Url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $this->rsaKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $this->foreignRsaKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $this->ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    $this->rsaJwk = function (OpenSSLAsymmetricKey $key, string $kid): array {
        $details = openssl_pkey_get_details($key);

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'n' => ($this->base64Url)($details['rsa']['n']), 'e' => ($this->base64Url)($details['rsa']['e'])];
    };

    $this->ecJwk = function (OpenSSLAsymmetricKey $key, string $kid): array {
        $details = openssl_pkey_get_details($key);

        return [
            'kty' => 'EC',
            'kid' => $kid,
            'crv' => 'P-256',
            'x' => ($this->base64Url)(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => ($this->base64Url)(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ];
    };

    $this->jwks = fn (): array => [($this->rsaJwk)($this->rsaKey, 'rsa-1'), ($this->ecJwk)($this->ecKey, 'ec-1')];

    $this->claims = static fn (array $overrides = []): array => [
        'iss' => 'https://idp.acme.test',
        'sub' => 'subject-4711',
        'aud' => 'client-acme',
        'exp' => Carbon::now()->addMinutes(5)->getTimestamp(),
        'iat' => Carbon::now()->getTimestamp(),
        'nonce' => 'nonce-abc',
        'email' => 'Ada@Acme.de',
        'email_verified' => true,
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
        ...$overrides,
    ];

    $this->derToRawSignature = static function (string $der): string {
        $offset = 2;
        $parts = [];

        for ($index = 0; $index < 2; $index++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($integer, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return implode('', $parts);
    };

    $this->token = function (array $claims, array $header = ['alg' => 'RS256', 'kid' => 'rsa-1'], ?OpenSSLAsymmetricKey $key = null): string {
        $signingInput = ($this->base64Url)(json_encode($header)).'.'.($this->base64Url)(json_encode($claims));
        $key ??= $header['alg'] === 'ES256' ? $this->ecKey : $this->rsaKey;

        openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($header['alg'] === 'ES256') {
            $signature = ($this->derToRawSignature)($signature);
        }

        return "{$signingInput}.".($this->base64Url)($signature);
    };

    $this->expectation = new IdTokenExpectation('https://idp.acme.test', 'client-acme', 'nonce-abc');

    $this->rejectionOf = function (string $token, ?array $jwks = null): string {
        try {
            app(IdTokenVerifier::class)->verify($token, $this->expectation, $jwks ?? ($this->jwks)());
        } catch (IdTokenRejectedException $exception) {
            return $exception->getMessage();
        }

        return 'accepted';
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('accepts an RS256 signed id token of the expected issuer, audience and nonce and hands back its claims', function (): void {
    $claims = app(IdTokenVerifier::class)->verify(($this->token)(($this->claims)()), $this->expectation, ($this->jwks)());

    expect($claims)->toBeInstanceOf(IdTokenClaims::class)
        ->and($claims->issuer)->toBe('https://idp.acme.test')
        ->and($claims->subject)->toBe('subject-4711')
        ->and($claims->email)->toBe('ada@acme.de')
        ->and($claims->isEmailVerified)->toBeTrue()
        ->and($claims->givenName)->toBe('Ada')
        ->and($claims->familyName)->toBe('Lovelace');
});

it('accepts an ES256 signed id token', function (): void {
    $token = ($this->token)(($this->claims)(), ['alg' => 'ES256', 'kid' => 'ec-1']);

    expect(($this->rejectionOf)($token))->toBe('accepted');
});

it('picks the only signing key of the matching type when the token names no key id', function (): void {
    $token = ($this->token)(($this->claims)(), ['alg' => 'RS256']);

    expect(($this->rejectionOf)($token, [($this->rsaJwk)($this->rsaKey, 'rsa-1')]))->toBe('accepted');
});

it('rejects a token signed by a key the provider does not publish', function (): void {
    $token = ($this->token)(($this->claims)(), ['alg' => 'RS256', 'kid' => 'rsa-1'], $this->foreignRsaKey);

    expect(($this->rejectionOf)($token))->toBe('The signature of the id token is invalid.');
});

it('rejects a token whose payload was changed after signing', function (): void {
    [$header, , $signature] = explode('.', ($this->token)(($this->claims)()));
    $forged = ($this->base64Url)(json_encode(($this->claims)(['sub' => 'admin'])));

    expect(($this->rejectionOf)("{$header}.{$forged}.{$signature}"))->toBe('The signature of the id token is invalid.');
});

it('rejects the unsigned and the symmetric algorithms', function (string $algorithm): void {
    $header = ($this->base64Url)(json_encode(['alg' => $algorithm, 'kid' => 'rsa-1']));
    $payload = ($this->base64Url)(json_encode(($this->claims)()));
    $signature = $algorithm === 'none' ? '' : ($this->base64Url)(hash_hmac('sha256', "{$header}.{$payload}", 'client-secret', true));

    expect(($this->rejectionOf)("{$header}.{$payload}.{$signature}"))->toBe('The id token uses an algorithm that is not accepted.');
})->with(['none', 'HS256', 'RS512']);

it('rejects a token whose key id the provider does not publish', function (): void {
    $token = ($this->token)(($this->claims)(), ['alg' => 'RS256', 'kid' => 'unknown']);

    expect(($this->rejectionOf)($token))->toBe('No published key matches the id token.');
});

it('rejects a malformed token', function (string $token): void {
    expect(($this->rejectionOf)($token))->toBe('The id token is malformed.');
})->with(['not-a-token', 'a.b', '!!!.???.***']);

it('rejects claims the provider was not expected to issue', function (array $overrides, string $reason): void {
    expect(($this->rejectionOf)(($this->token)(($this->claims)($overrides))))->toBe($reason);
})->with([
    'foreign issuer' => [['iss' => 'https://evil.test'], 'The issuer of the id token does not match.'],
    'issuer differing by a trailing slash' => [['iss' => 'https://idp.acme.test/'], 'The issuer of the id token does not match.'],
    'unresolved entra placeholder' => [['iss' => 'https://login.microsoftonline.com/{tenantid}/v2.0'], 'The issuer of the id token does not match.'],
    'foreign audience' => [['aud' => 'client-other'], 'The id token was not issued for this client.'],
    'audience list without the client' => [['aud' => ['client-other', 'client-third']], 'The id token was not issued for this client.'],
    'several audiences without authorized party' => [['aud' => ['client-acme', 'client-other']], 'The authorized party of the id token does not match.'],
    'foreign authorized party' => [['aud' => ['client-acme', 'client-other'], 'azp' => 'client-other'], 'The authorized party of the id token does not match.'],
    'expired beyond the leeway' => [['exp' => Carbon::parse('2026-10-30 11:58:59', 'UTC')->getTimestamp()], 'The id token has expired.'],
    'without expiry' => [['exp' => null], 'The id token has expired.'],
    'issued in the future beyond the leeway' => [['iat' => Carbon::parse('2026-10-30 12:01:01', 'UTC')->getTimestamp()], 'The id token was issued in the future.'],
    'foreign nonce' => [['nonce' => 'nonce-replayed'], 'The nonce of the id token does not match.'],
    'without nonce' => [['nonce' => null], 'The nonce of the id token does not match.'],
    'without subject' => [['sub' => ''], 'The id token names no subject.'],
]);

it('tolerates a clock skew inside the leeway', function (): void {
    $token = ($this->token)(($this->claims)([
        'exp' => Carbon::parse('2026-10-30 11:59:30', 'UTC')->getTimestamp(),
        'iat' => Carbon::parse('2026-10-30 12:00:30', 'UTC')->getTimestamp(),
    ]));

    expect(($this->rejectionOf)($token))->toBe('accepted');
});

it('accepts a single authorized party that names the client among several audiences', function (): void {
    $token = ($this->token)(($this->claims)(['aud' => ['client-acme', 'client-other'], 'azp' => 'client-acme']));

    expect(($this->rejectionOf)($token))->toBe('accepted');
});

it('reports an unverified or missing email as unverified', function (mixed $verified): void {
    $claims = app(IdTokenVerifier::class)->verify(($this->token)(($this->claims)(['email_verified' => $verified])), $this->expectation, ($this->jwks)());

    expect($claims->isEmailVerified)->toBeFalse();
})->with([false, 'true', null]);
