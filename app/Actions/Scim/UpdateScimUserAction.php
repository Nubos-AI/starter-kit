<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Users\SetUserStatusAction;
use App\DTOs\Scim\ScimUserChanges;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\UserStatus;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Exceptions\Scim\ScimException;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UpdateScimUserAction
{
    public function __construct(
        private readonly SetUserStatusAction $setUserStatus,
        private readonly LinkUserIdentityAction $linkIdentity,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(User $user, ScimUserChanges $changes): User
    {
        $email = isset($changes->values['email']) ? Str::lower(trim($changes->values['email'])) : null;

        if ($email !== null && $email !== Str::lower($user->email) && User::withTrashed()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            throw ScimException::uniqueness();
        }

        try {
            return DB::transaction(function () use ($user, $changes): User {
                $user->fill(Arr::only($changes->values, ['email', 'first_name', 'last_name']));

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

    private function relinkExternalId(User $user, ?string $externalId): void
    {
        UserIdentity::query()
            ->where('user_id', $user->getKey())
            ->where('provider', IdentityProvider::Scim->value)
            ->where('subject', '!=', $externalId ?? '')
            ->delete();

        if ($externalId !== null) {
            $this->linkIdentity->execute($user, IdentityProvider::Scim, CreateScimUserAction::$issuer, $externalId);
        }
    }
}
