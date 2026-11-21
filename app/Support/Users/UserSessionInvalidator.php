<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\ScimToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserSessionInvalidator
{
    public function invalidate(User $user, ?string $keptSessionId = null): void
    {
        $this->endInteractiveSessions($user, $keptSessionId);

        $user->tokens()->delete();

        PersonalAccessToken::query()
            ->where('creator_id', $user->getKey())
            ->delete();

        ScimToken::withoutTenantScope()
            ->where('created_by_id', $user->getKey())
            ->delete();
    }

    public function endInteractiveSessions(User $user, ?string $keptSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($keptSessionId !== null, static fn ($query) => $query->where('id', '!=', $keptSessionId))
            ->delete();

        $user->forceFill(['remember_token' => null])->save();
    }
}
