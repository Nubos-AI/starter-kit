<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\OidcProviderException;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Sso\OidcProviderClient;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StartSsoLoginTestAction
{
    public function __construct(
        private readonly SsoConnectionLookup $connections,
        private readonly OidcProviderClient $provider,
        private readonly OidcAuthorizationFlow $flow,
    ) {}

    /**
     * @throws ValidationException
     */
    public function execute(User $initiator, Session $session): string
    {
        $connection = $this->connections->forTenant((string) $initiator->tenant_id);

        if (!$connection instanceof SsoConnection || !$connection->isReadyForSignIn()) {
            Log::info('A single sign-on login test was refused because no complete connection is saved.', ['tenant_id' => $initiator->tenant_id, 'user_id' => $initiator->getKey()]);

            throw ValidationException::withMessages(['login_test' => __('i18n.backend.actions.sso.start_sso_login_test_action.connection_incomplete')]);
        }

        try {
            $metadata = $this->provider->metadata((string) $connection->issuer_url);
        } catch (OidcProviderException $exception) {
            Log::info('A single sign-on login test failed during the discovery.', ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey(), 'reason' => $exception->reason]);

            throw ValidationException::withMessages(['login_test' => $exception->userMessage()]);
        }

        $url = $this->flow->begin(
            $connection,
            $metadata,
            $session,
            SsoLoginPurpose::ConnectionTest,
            initiatorId: (string) $initiator->getKey(),
            returnUrl: route('engine.sso.edit'),
        );

        Log::info('A single sign-on login test was started.', ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey(), 'user_id' => $initiator->getKey()]);

        return $url;
    }
}
