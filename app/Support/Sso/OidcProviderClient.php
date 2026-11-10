<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\DTOs\Sso\OidcProviderMetadata;
use App\Exceptions\Sso\OidcProviderException;
use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Support\Webhooks\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OidcProviderClient
{
    private string $discoveryPath = '/.well-known/openid-configuration';

    private string $tenantPlaceholder = '{tenantid}';

    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    /**
     * @throws OidcProviderException
     */
    public function metadata(string $issuerUrl): OidcProviderMetadata
    {
        $issuer = rtrim(trim($issuerUrl), '/');

        /** @var array<string, mixed> $document */
        $document = Cache::remember(
            $this->cacheKey('discovery', $issuer),
            (int) config('sso.oidc.discovery_cache_seconds'),
            fn (): array => $this->fetchDiscovery($issuer),
        );

        $metadata = OidcProviderMetadata::fromArray($document);

        if ($metadata === null) {
            throw OidcProviderException::invalidDocument();
        }

        return $metadata;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws OidcProviderException
     */
    public function signingKeys(OidcProviderMetadata $metadata, bool $refresh = false): array
    {
        $key = $this->cacheKey('jwks', $metadata->jwksUri);

        if ($refresh) {
            Cache::forget($key);
        }

        /** @var list<array<string, mixed>> $keys */
        $keys = Cache::remember($key, (int) config('sso.oidc.jwks_cache_seconds'), function () use ($metadata): array {
            $keys = $this->get($metadata->jwksUri)['keys'] ?? null;

            if (!is_array($keys)) {
                throw OidcProviderException::unexpectedAnswer();
            }

            return array_values(array_filter($keys, 'is_array'));
        });

        return $keys;
    }

    /**
     * @throws OidcProviderException
     */
    public function exchangeCode(
        OidcProviderMetadata $metadata,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): string {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        $request = $this->pinnedRequest($metadata->tokenEndpoint)->asForm();

        if ($metadata->sendsSecretInBody()) {
            $form = [...$form, 'client_id' => $clientId, 'client_secret' => $clientSecret];
        } else {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $idToken = $this->decoded($metadata->tokenEndpoint, fn (): Response => $request->post($metadata->tokenEndpoint, $form))['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw OidcProviderException::unexpectedAnswer();
        }

        return $idToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDiscovery(string $issuer): array
    {
        $document = $this->get("{$issuer}{$this->discoveryPath}");

        if (is_string($document['issuer'] ?? null) && Str::contains($document['issuer'], $this->tenantPlaceholder, true)) {
            throw OidcProviderException::multiTenantIssuer();
        }

        if (!is_string($document['issuer'] ?? null) || rtrim($document['issuer'], '/') !== $issuer) {
            throw OidcProviderException::invalidDocument();
        }

        if (OidcProviderMetadata::fromArray($document) === null) {
            throw OidcProviderException::invalidDocument();
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        return $this->decoded($url, fn (): Response => $this->pinnedRequest($url)->acceptJson()->get($url));
    }

    /**
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     */
    private function decoded(string $url, callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            Log::warning('The identity provider could not be reached.', ['host' => parse_url($url, PHP_URL_HOST)]);

            throw OidcProviderException::unexpectedAnswer($exception);
        }

        $payload = $response->json();

        if (!$response->successful() || !is_array($payload)) {
            Log::warning('The identity provider answered unexpectedly.', ['host' => parse_url($url, PHP_URL_HOST), 'status' => $response->status()]);

            throw OidcProviderException::unexpectedAnswer();
        }

        return $payload;
    }

    private function pinnedRequest(string $url): PendingRequest
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw OidcProviderException::insecureAddress();
        }

        try {
            $pinnedIp = $this->ssrfGuard->assertWebhookTargetAllowed($url);
        } catch (SsrfBlockedException $exception) {
            Log::warning('An identity provider address was refused by the SSRF guard.', ['host' => parse_url($url, PHP_URL_HOST)]);

            throw OidcProviderException::blockedAddress($exception);
        }

        return Http::withoutRedirecting()
            ->timeout((int) config('sso.oidc.http_timeout_seconds'))
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->ssrfGuard->resolveEntryFor($url, $pinnedIp)]]]);
    }

    private function cacheKey(string $kind, string $url): string
    {
        $hash = hash('sha256', $url);

        return "sso:oidc:{$kind}:{$hash}";
    }
}
