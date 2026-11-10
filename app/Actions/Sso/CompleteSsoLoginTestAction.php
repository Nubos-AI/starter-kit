<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoFailure;
use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\SsoLoginException;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteSsoLoginTestAction
{
    public function __construct(
        private readonly OidcAuthorizationFlow $flow,
        private readonly AdminArtifactAuditor $auditor,
        private readonly TenantBinder $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SsoLoginException
     */
    public function execute(array $query, Session $session, ?User $actingUser): SsoConnection
    {
        $pending = $this->flow->resume($query, $session, SsoLoginPurpose::ConnectionTest);

        Log::info('A single sign-on login test returned from the identity provider.', ['tenant_id' => $pending->tenantId, 'connection_id' => $pending->connectionId]);

        if (!$this->isInitiator($actingUser, $pending)) {
            Log::warning('A single sign-on login test returned to someone who did not start it.', ['tenant_id' => $pending->tenantId, 'connection_id' => $pending->connectionId]);

            throw new SsoLoginException(SsoFailure::ForeignInitiator);
        }

        $connection = $this->flow->connectionOf($pending);
        $claims = $this->flow->verifiedClaims($connection, $pending, (string) ($query['code'] ?? ''));

        $this->tenants->run($connection->tenant_id, function () use ($connection, $claims): void {
            DB::transaction(function () use ($connection, $claims): void {
                $connection->fill([
                    'login_verified_at' => now(),
                    'login_verified_fingerprint' => $connection->loginFingerprint(),
                ])->save();

                $this->auditor->recordEvent($connection, 'sso.login_verified', [
                    'issuer_url' => $connection->issuer_url,
                    'client_id' => $connection->client_id,
                    'issuer' => $claims->issuer,
                ], $connection->tenant_id);
            });
        });

        Log::info('A single sign-on login test succeeded.', ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey(), 'user_id' => $actingUser?->getKey()]);

        return $connection;
    }

    private function isInitiator(?User $actingUser, PendingSsoLogin $pending): bool
    {
        return $actingUser instanceof User
            && $pending->initiatorId !== null
            && (string) $actingUser->getKey() === $pending->initiatorId
            && (string) $actingUser->tenant_id === $pending->tenantId;
    }
}
