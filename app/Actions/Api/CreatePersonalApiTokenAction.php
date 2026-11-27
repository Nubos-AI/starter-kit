<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Enums\Api\ApiTokenAbility;
use App\Models\User;
use App\Traits\Api\ResolvesTokenExpiry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;

class CreatePersonalApiTokenAction
{
    use ResolvesTokenExpiry;

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $user, array $input): NewAccessToken
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', Rule::enum(ApiTokenAbility::class)],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ])->validate();

        return $user->createToken(
            $validated['name'],
            array_values($validated['abilities']),
            $this->tokenExpiry($validated['expiresAt'] ?? null),
        );
    }
}
