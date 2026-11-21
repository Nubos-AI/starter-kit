<?php

declare(strict_types=1);

namespace App\DTOs\Sso;

use App\Enums\Sso\SsoLoginPurpose;

readonly class PendingSsoLogin
{
    public function __construct(
        public string $connectionId,
        public string $tenantId,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public int $issuedAt,
        public SsoLoginPurpose $purpose = SsoLoginPurpose::Login,
        public ?string $initiatorId = null,
        public ?string $returnUrl = null,
    ) {}

    public static function fromSession(mixed $stored): ?self
    {
        if (!is_array($stored)) {
            return null;
        }

        foreach (['connection_id', 'tenant_id', 'state', 'nonce', 'code_verifier'] as $key) {
            if (!is_string($stored[$key] ?? null) || $stored[$key] === '') {
                return null;
            }
        }

        $purpose = is_string($stored['purpose'] ?? null) ? SsoLoginPurpose::tryFrom($stored['purpose']) : null;

        if (!is_int($stored['issued_at'] ?? null) || !$purpose instanceof SsoLoginPurpose) {
            return null;
        }

        return new self(
            $stored['connection_id'],
            $stored['tenant_id'],
            $stored['state'],
            $stored['nonce'],
            $stored['code_verifier'],
            $stored['issued_at'],
            $purpose,
            is_string($stored['initiator_id'] ?? null) ? $stored['initiator_id'] : null,
            is_string($stored['return_url'] ?? null) ? $stored['return_url'] : null,
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toSession(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'tenant_id' => $this->tenantId,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'issued_at' => $this->issuedAt,
            'purpose' => $this->purpose->value,
            'initiator_id' => $this->initiatorId,
            'return_url' => $this->returnUrl,
        ];
    }

    public function isExpired(): bool
    {
        return $this->issuedAt + (int) config('sso.oidc.pending_login_ttl_seconds') < now()->getTimestamp();
    }
}
