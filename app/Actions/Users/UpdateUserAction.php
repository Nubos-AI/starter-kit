<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Validator;

class UpdateUserAction
{
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $user, array $attributes): User
    {
        $attributes = Validator::make(
            $attributes,
            $this->profileRules((string) $user->getKey()),
        )->validate();

        $user->fill($attributes);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }
}
