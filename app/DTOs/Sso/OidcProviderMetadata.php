<?php

declare(strict_types=1);

namespace App\DTOs\Sso;

readonly class OidcProviderMetadata
{
    /**
     * @param  list<string>  $tokenEndpointAuthMethods
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $tokenEndpointAuthMethods,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromArray(array $document): ?self
    {
        $endpoints = [
            $document['issuer'] ?? null,
            $document['authorization_endpoint'] ?? null,
            $document['token_endpoint'] ?? null,
            $document['jwks_uri'] ?? null,
        ];

        foreach ($endpoints as $endpoint) {
            if (!is_string($endpoint) || !str_starts_with($endpoint, 'https://')) {
                return null;
            }
        }

        $methods = $document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];

        return new self(
            $endpoints[0],
            $endpoints[1],
            $endpoints[2],
            $endpoints[3],
            is_array($methods) ? array_values(array_filter($methods, 'is_string')) : ['client_secret_basic'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethods,
        ];
    }

    public function sendsSecretInBody(): bool
    {
        return !in_array('client_secret_basic', $this->tokenEndpointAuthMethods, true)
            && in_array('client_secret_post', $this->tokenEndpointAuthMethods, true);
    }
}
