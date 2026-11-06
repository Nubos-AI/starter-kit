<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\Salutation;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AcceptInvitationAction
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        private readonly ConsumeInvitationAction $consumeInvitation,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvitationNotAcceptableException
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make(
            $data,
            [
                'salutation' => $this->salutationRules(),
                'first_name' => $this->firstNameRules(),
                'last_name' => $this->lastNameRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        $this->consumeInvitation->execute($user, [
            'salutation' => $validated['salutation'] ?? Salutation::Unknown->value,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'password' => Hash::make((string) $validated['password']),
        ]);
    }
}
