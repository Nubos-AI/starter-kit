<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Enums\Sso\SsoFailure;
use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\OidcProviderException;
use App\Models\SsoConnection;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Sso\OidcProviderClient;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartSsoLoginAction
{
    public function __construct(
        private readonly SsoConnectionLookup $connections,
        private readonly OidcProviderClient $provider,
        private readonly OidcAuthorizationFlow $flow,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(array $data, Session $session): string
    {
        $validated = Validator::make($data, ['email' => ['required', 'string', 'email', 'max:255']])->validate();

        $email = Str::lower(trim((string) $validated['email']));
        $connection = $this->connections->enabledForDomain(Str::after($email, '@'));

        if (!$connection instanceof SsoConnection || $connection->issuer_url === null || $connection->client_id === null) {
            Log::info('A single sign-on was requested for a domain without an enabled connection.');

            throw ValidationException::withMessages(['email' => SsoFailure::NoConnection->label()]);
        }

        try {
            $metadata = $this->provider->metadata($connection->issuer_url);
        } catch (OidcProviderException $exception) {
            Log::warning('The identity provider of a single sign-on could not be discovered.', ['tenant_id' => $connection->tenant_id, 'reason' => $exception->getMessage()]);

            throw ValidationException::withMessages(['email' => SsoFailure::ProviderUnavailable->label()]);
        }

        $url = $this->flow->begin($connection, $metadata, $session, SsoLoginPurpose::Login, loginHint: $email);

        Log::info('A single sign-on was started.', ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey()]);

        return $url;
    }
}
