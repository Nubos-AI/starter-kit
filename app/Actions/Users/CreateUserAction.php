<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Tenancy\ProvisionTenantAction;
use App\DTOs\Tenancy\TenantRegistrationData;
use App\Enums\Users\Salutation;
use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Throwable;

class CreateUserAction implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(private readonly ProvisionTenantAction $provisionTenant) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws Throwable
     */
    public function create(array $input): User
    {
        $validated = Validator::make(
            $input,
            [
                'company_name' => ['required', 'string', 'max:255'],
                'salutation' => $this->salutationRules(),
                'first_name' => $this->firstNameRules(),
                'last_name' => $this->lastNameRules(),
                'email' => $this->emailRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        return $this->provisionTenant->execute(new TenantRegistrationData(
            companyName: $validated['company_name'],
            salutation: Salutation::tryFrom((string) ($validated['salutation'] ?? '')) ?? Salutation::Unknown,
            firstName: $validated['first_name'],
            lastName: $validated['last_name'],
            email: $validated['email'],
            password: $validated['password'],
        ));
    }
}
