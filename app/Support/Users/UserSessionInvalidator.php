<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSessionInvalidator
{
    public function invalidate(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        $user->forceFill(['remember_token' => null])->save();
    }
}
