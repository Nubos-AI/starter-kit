<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenPresenter
{
    /**
     * @param  Collection<int, PersonalAccessToken>  $tokens
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(Collection $tokens): array
    {
        return $tokens->map(fn (PersonalAccessToken $token): array => $this->present($token))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PersonalAccessToken $token): array
    {
        $owner = $token->tokenable;

        return [
            'id' => (string) $token->getKey(),
            'name' => $token->name,
            'abilities' => array_values($token->abilities ?? []),
            'ownerName' => $owner instanceof User ? $owner->name : null,
            'ownerEmail' => $owner instanceof User ? $owner->email : null,
            'isService' => $owner instanceof User && $owner->is_service,
            'lastUsedAt' => $token->last_used_at?->toIso8601String(),
            'expiresAt' => $token->expires_at?->toIso8601String(),
            'createdAt' => $token->created_at?->toIso8601String(),
        ];
    }
}
