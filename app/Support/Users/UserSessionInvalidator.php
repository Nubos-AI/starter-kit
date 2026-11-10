<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSessionInvalidator
{
    public function invalidate(User $user, ?string $keptSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($keptSessionId !== null, static fn ($query) => $query->where('id', '!=', $keptSessionId))
            ->delete();

        $user->tokens()->delete();

        $user->forceFill(['remember_token' => null])->save();
    }
}
