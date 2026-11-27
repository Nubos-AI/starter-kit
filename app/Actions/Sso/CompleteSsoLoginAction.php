<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Enums\Sso\SsoLoginPurpose;
use App\Exceptions\Sso\SsoLoginException;
use App\Models\User;
use App\Support\Sso\OidcAuthorizationFlow;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Log;

class CompleteSsoLoginAction
{
    public function __construct(
        private readonly OidcAuthorizationFlow $flow,
        private readonly ResolveSsoUserAction $resolveUser,
        private readonly TenantBinder $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SsoLoginException
     */
    public function execute(array $query, Session $session): User
    {
        $pending = $this->flow->resume($query, $session, SsoLoginPurpose::Login);
        $connection = $this->flow->connectionOf($pending);
        $claims = $this->flow->verifiedClaims($connection, $pending, (string) ($query['code'] ?? ''));

        $user = $this->tenants->run($connection->tenant_id, fn (): User => $this->resolveUser->execute($connection, $claims));

        Log::info('A single sign-on completed.', ['tenant_id' => $connection->tenant_id, 'user_id' => $user->getKey()]);

        return $user;
    }
}
