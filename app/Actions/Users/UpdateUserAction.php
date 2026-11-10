<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
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
        $rules = $user->status === UserStatus::Invited
            ? $this->profileRules((string) $user->getKey())
            : $this->personalDetailsRules();

        $user->fill(Validator::make($this->withNormalizedEmail($attributes), $rules)->validate());

        $user->save();

        return $user;
    }
}
