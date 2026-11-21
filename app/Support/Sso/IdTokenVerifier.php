<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\IdTokenExpectation;
use App\Enums\Sso\JwsAlgorithm;
use App\Exceptions\Sso\IdTokenRejectedException;
use Illuminate\Support\Str;

class IdTokenVerifier
{
    public function __construct(private readonly JwkPublicKeyConverter $keys) {}

    /**
     * @param  list<array<string, mixed>>  $jwks
     *
     * @throws IdTokenRejectedException
     */
    public function verify(string $idToken, IdTokenExpectation $expected, array $jwks): IdTokenClaims
    {
        [$header, $claims, $signingInput, $signature] = $this->decode($idToken);

        $algorithm = JwsAlgorithm::tryFrom((string) ($header['alg'] ?? ''));

        if ($algorithm === null) {
            throw IdTokenRejectedException::algorithmNotAccepted();
        }

        $this->assertSignature($algorithm, $this->keyFor($algorithm, $header, $jwks), $signingInput, $signature);
        $this->assertClaims($claims, $expected);

        return new IdTokenClaims(
            (string) $claims['iss'],
            (string) $claims['sub'],
            is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : null,
            ($claims['email_verified'] ?? null) === true,
            is_string($claims['given_name'] ?? null) ? $claims['given_name'] : null,
            is_string($claims['family_name'] ?? null) ? $claims['family_name'] : null,
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decode(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw IdTokenRejectedException::malformed();
        }

        $header = $this->decodeJson($segments[0]);
        $claims = $this->decodeJson($segments[1]);
        $signature = $this->keys->decodeBase64Url($segments[2]);

        if ($header === null || $claims === null || $signature === null) {
            throw IdTokenRejectedException::malformed();
        }

        return [$header, $claims, "{$segments[0]}.{$segments[1]}", $signature];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $segment): ?array
    {
        $json = $this->keys->decodeBase64Url($segment);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $jwks
     */
    private function keyFor(JwsAlgorithm $algorithm, array $header, array $jwks): string
    {
        $candidates = array_values(array_filter(
            $jwks,
            static fn (array $jwk): bool => ($jwk['kty'] ?? null) === $algorithm->keyType()
                && ($jwk['use'] ?? 'sig') === 'sig',
        ));

        $kid = $header['kid'] ?? null;

        if (is_string($kid)) {
            $candidates = array_values(array_filter($candidates, static fn (array $jwk): bool => ($jwk['kid'] ?? null) === $kid));
        }

        $pem = count($candidates) === 1 ? $this->keys->toPem($candidates[0]) : null;

        if ($pem === null) {
            throw IdTokenRejectedException::unknownKey();
        }

        return $pem;
    }

    private function assertSignature(JwsAlgorithm $algorithm, string $pem, string $signingInput, string $signature): void
    {
        $signature = $algorithm === JwsAlgorithm::Es256 ? $this->keys->rawSignatureToDer($signature) : $signature;

        $isValid = $signature !== null
            && openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;

        if (!$isValid) {
            throw IdTokenRejectedException::invalidSignature();
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaims(array $claims, IdTokenExpectation $expected): void
    {
        if (($claims['iss'] ?? null) !== $expected->issuer) {
            throw IdTokenRejectedException::foreignIssuer();
        }

        $this->assertAudience($claims, $expected->clientId);
        $this->assertLifetime($claims);

        $nonce = $claims['nonce'] ?? null;

        if (!is_string($nonce) || !hash_equals($expected->nonce, $nonce)) {
            throw IdTokenRejectedException::foreignNonce();
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw IdTokenRejectedException::missingSubject();
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims, string $clientId): void
    {
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];

        if (!in_array($clientId, $audiences, true)) {
            throw IdTokenRejectedException::foreignAudience();
        }

        $authorizedParty = $claims['azp'] ?? null;

        if ($authorizedParty === null && count($audiences) === 1) {
            return;
        }

        if ($authorizedParty !== $clientId) {
            throw IdTokenRejectedException::foreignAuthorizedParty();
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertLifetime(array $claims): void
    {
        $leeway = (int) config('sso.oidc.clock_leeway_seconds');
        $now = now()->getTimestamp();
        $expiresAt = $claims['exp'] ?? null;
        $issuedAt = $claims['iat'] ?? null;

        if (!is_int($expiresAt) || $expiresAt + $leeway < $now) {
            throw IdTokenRejectedException::expired();
        }

        if (!is_int($issuedAt) || $issuedAt - $leeway > $now) {
            throw IdTokenRejectedException::issuedInTheFuture();
        }
    }
}
