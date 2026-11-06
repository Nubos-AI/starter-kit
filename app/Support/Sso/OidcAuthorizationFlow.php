<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\IdTokenExpectation;
use App\DTOs\Sso\OidcProviderMetadata;
use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoFailure;
use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\IdTokenRejectedException;
use App\Exceptions\Sso\OidcProviderException;
use App\Exceptions\Sso\SsoLoginException;
use App\Models\SsoConnection;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OidcAuthorizationFlow
{
    public static string $sessionKey = 'sso.pending';

    private int $stateLength = 48;

    private int $codeVerifierLength = 64;

    public function __construct(
        private readonly SsoConnectionLookup $connections,
        private readonly OidcProviderClient $provider,
        private readonly IdTokenVerifier $verifier,
    ) {}

    public function begin(
        SsoConnection $connection,
        OidcProviderMetadata $metadata,
        Session $session,
        SsoLoginPurpose $purpose,
        ?string $loginHint = null,
        ?string $initiatorId = null,
        ?string $returnUrl = null,
    ): string {
        $pending = new PendingSsoLogin(
            (string) $connection->getKey(),
            $connection->tenant_id,
            Str::random($this->stateLength),
            Str::random($this->stateLength),
            Str::random($this->codeVerifierLength),
            now()->getTimestamp(),
            $purpose,
            $initiatorId,
            $returnUrl,
        );

        $session->put(self::$sessionKey, $pending->toSession());

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $pending->codeVerifier, true)), '+/', '-_'), '=');

        $query = Arr::query(array_filter([
            'response_type' => 'code',
            'client_id' => (string) $connection->client_id,
            'redirect_uri' => route('sso.callback'),
            'scope' => implode(' ', (array) config('sso.oidc.scopes')),
            'state' => $pending->state,
            'nonce' => $pending->nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'login_hint' => $loginHint,
        ], static fn (?string $value): bool => $value !== null));

        $endpoint = $metadata->authorizationEndpoint;
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return "{$endpoint}{$separator}{$query}";
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SsoLoginException
     */
    public function resume(array $query, Session $session, SsoLoginPurpose $purpose): PendingSsoLogin
    {
        $pending = PendingSsoLogin::fromSession($session->pull(self::$sessionKey));
        $state = $query['state'] ?? null;

        if (!$pending instanceof PendingSsoLogin || $pending->purpose !== $purpose || $pending->isExpired() || !is_string($state) || !hash_equals($pending->state, $state)) {
            throw new SsoLoginException(SsoFailure::SessionExpired);
        }

        if (isset($query['error'])) {
            throw new SsoLoginException(SsoFailure::ProviderDenied);
        }

        return $pending;
    }

    /**
     * @throws SsoLoginException
     */
    public function connectionOf(PendingSsoLogin $pending): SsoConnection
    {
        $connection = $this->connections->forTenant($pending->tenantId);

        if (!$connection instanceof SsoConnection || !$connection->is_enabled || (string) $connection->getKey() !== $pending->connectionId) {
            throw new SsoLoginException(SsoFailure::NoConnection);
        }

        return $connection;
    }

    /**
     * @throws SsoLoginException
     */
    public function verifiedClaims(SsoConnection $connection, PendingSsoLogin $pending, string $code): IdTokenClaims
    {
        $context = ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey(), 'purpose' => $pending->purpose->value];

        try {
            $metadata = $this->provider->metadata((string) $connection->issuer_url);
            $idToken = $this->provider->exchangeCode(
                $metadata,
                (string) $connection->client_id,
                (string) $connection->client_secret,
                $code,
                route('sso.callback'),
                $pending->codeVerifier,
            );

            return $this->verify($metadata, $idToken, new IdTokenExpectation($metadata->issuer, (string) $connection->client_id, $pending->nonce));
        } catch (OidcProviderException $exception) {
            Log::warning('The identity provider failed during a single sign-on.', [...$context, 'reason' => $exception->getMessage()]);

            throw new SsoLoginException(SsoFailure::ProviderUnavailable, $exception);
        } catch (IdTokenRejectedException $exception) {
            Log::warning('An id token was rejected during a single sign-on.', [...$context, 'reason' => $exception->getMessage()]);

            throw new SsoLoginException(SsoFailure::TokenRejected, $exception);
        }
    }

    private function verify(OidcProviderMetadata $metadata, string $idToken, IdTokenExpectation $expected): IdTokenClaims
    {
        try {
            return $this->verifier->verify($idToken, $expected, $this->provider->signingKeys($metadata));
        } catch (IdTokenRejectedException $exception) {
            if (!$exception->isUnknownKey) {
                throw $exception;
            }

            return $this->verifier->verify($idToken, $expected, $this->provider->signingKeys($metadata, true));
        }
    }
}
