<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Actions\Users\SetUserStatusAction;
use App\DTOs\Scim\ScimUserChanges;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UpdateScimUserAction
{
    public function __construct(
        private readonly SetUserStatusAction $setUserStatus,
        private readonly ReleaseUnverifiedRegistrationAction $releaseRegistration,
        private readonly LinkScimIdentityAction $linkIdentity,
        private readonly AdminArtifactAuditor $auditor,
        private readonly ScimAccessGuard $accessGuard,
        private readonly SsoConnectionLookup $connections,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(ScimToken $token, User $user, ScimUserChanges $changes): User
    {
        $this->accessGuard->assertMaySteer($token, $user);

        $email = isset($changes->values['email']) ? Str::lower(trim($changes->values['email'])) : null;
        $isNewEmail = $email !== null && $email !== Str::lower($user->email);

        if ($isNewEmail) {
            $this->accessGuard->assertClaimedEmail($token, $this->connections->forTenant($token->tenant_id), $email);
        }

        $holder = $isNewEmail ? User::withTrashed()->where('email', $email)->whereKeyNot($user->getKey())->first() : null;

        if ($holder instanceof User && !$this->releaseRegistration->execute($holder, RegistrationReleaseReason::ClaimedByScim)) {
            throw ScimException::uniqueness();
        }

        try {
            return DB::transaction(function () use ($user, $changes, $isNewEmail): User {
                $user->fill(Arr::only($changes->values, ['email', 'first_name', 'last_name']));

                if ($isNewEmail) {
                    $user->forceFill(['email_verified_at' => now()]);
                }

                if ($user->isDirty()) {
                    $user->save();
                }

                $this->applyStatus($user, $changes);

                if ($changes->has('external_id')) {
                    $this->relinkExternalId($user, $changes->values['external_id'] ?? null);
                }

                $this->auditor->recordEvent($user, 'scim.user_updated', ['attributes' => array_keys($changes->values)], $user->tenant_id);

                Log::info('A user was updated through scim.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

                return $user;
            });
        } catch (SelfLockoutException $exception) {
            throw ScimException::mutability($exception->getMessage());
        }
    }

    private function applyStatus(User $user, ScimUserChanges $changes): void
    {
        $isActive = $changes->values['is_active'] ?? null;

        if ($isActive === null || $isActive === ($user->status === UserStatus::Accepted)) {
            return;
        }

        $this->setUserStatus->execute($user, ['status' => $isActive ? UserStatus::Accepted->value : UserStatus::Blocked->value]);
    }

    /**
     * @throws ScimException
     */
    private function relinkExternalId(User $user, ?string $externalId): void
    {
        $previous = UserIdentity::query()
            ->where('user_id', $user->getKey())
            ->where('provider', IdentityProvider::Scim->value);

        if ($externalId !== null) {
            $previous->whereKeyNot($this->linkIdentity->execute($user, $externalId)->getKey());
        }

        $previous->delete();
    }
}
