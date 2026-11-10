<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;
use Throwable;

class OidcProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return __("i18n.backend.actions.sso.test_sso_connection_action.{$this->reason}");
    }

    public static function blockedAddress(?Throwable $previous = null): self
    {
        return new self('The identity provider address is not allowed.', 'blocked_address', $previous);
    }

    public static function insecureAddress(): self
    {
        return new self('The identity provider must be reached over https.', 'insecure_address');
    }

    public static function invalidDocument(): self
    {
        return new self('The discovery document of the identity provider is invalid.', 'invalid_document');
    }

    public static function multiTenantIssuer(): self
    {
        return new self('The discovery document names a multi tenant issuer placeholder.', 'multi_tenant_issuer');
    }

    public static function unexpectedAnswer(?Throwable $previous = null): self
    {
        return new self('The identity provider did not answer as expected.', 'unreachable', $previous);
    }
}
