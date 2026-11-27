<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RevokeLocalSessionsAction
{
    public function __construct(
        private readonly SsoEnforcementGate $gate,
        private readonly UserSessionInvalidator $sessions,
    ) {}

    /**
     * @param  list<string>  $domains
     */
    public function execute(SsoConnection $connection, array $domains, ?User $keptUser = null): int
    {
        if ($domains === []) {
            return 0;
        }

        $revoked = 0;

        User::query()
            ->where('users.tenant_id', $connection->tenant_id)
            ->where('users.is_service', false)
            ->when($keptUser instanceof User, static fn (Builder $users): Builder => $users->whereKeyNot($keptUser?->getKey()))
            ->where(static function (Builder $users) use ($domains): void {
                foreach ($domains as $domain) {
                    $users->orWhereLike('users.email', "%@{$domain}");
                }
            })
            ->lazyById()
            ->filter(fn (User $user): bool => $this->gate->blocksLocalLoginThrough($connection, $user))
            ->each(function (User $user) use (&$revoked): void {
                $this->sessions->endInteractiveSessions($user);
                $revoked++;
            });

        Log::info('Password sessions were ended because single sign-on is now enforced for their domain.', ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->getKey(), 'revoked' => $revoked]);

        return $revoked;
    }
}
