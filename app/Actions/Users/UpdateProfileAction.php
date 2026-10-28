<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Validator;

class UpdateProfileAction
{
    use ProfileValidationRules;

    public function __construct(private readonly UpdateUserAction $updateUser) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): User
    {
        $validated = Validator::make($data, $this->profileRules($user->id))->validate();

        return $this->updateUser->execute($user, $validated);
    }
}
