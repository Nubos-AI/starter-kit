<?php

declare(strict_types=1);

namespace App\Traits\Users;

use App\Enums\Users\Salutation;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * @return array<string, array<int, Enum|Unique|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null): array
    {
        return [
            'salutation' => $this->salutationRules(),
            'first_name' => $this->firstNameRules(),
            'last_name' => $this->lastNameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withNormalizedEmail(array $data): array
    {
        if (!is_string($data['email'] ?? null)) {
            return $data;
        }

        return [...$data, 'email' => Str::lower(trim($data['email']))];
    }

    /**
     * @return array<int, Enum|ValidationRule|array<mixed>|string>
     */
    protected function salutationRules(): array
    {
        return ['nullable', Rule::enum(Salutation::class)];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function firstNameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function lastNameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
