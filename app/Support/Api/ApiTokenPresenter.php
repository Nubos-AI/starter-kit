<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenPresenter
{
    private string $beyondOwnRightsReason = 'i18n.backend.support.api.api_token_presenter.this_account_holds_more_rights_than_you';

    /**
     * @param  Collection<int, PersonalAccessToken>  $tokens
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(Collection $tokens): array
    {
        return $tokens->map(fn (PersonalAccessToken $token): array => $this->present($token))->all();
    }

    /**
     * @param  Collection<int, PersonalAccessToken>  $tokens
     * @param  list<string>  $revocableOwnerKeys
     * @return array<int, array<string, mixed>>
     */
    public function presentManyForAdministration(Collection $tokens, array $revocableOwnerKeys): array
    {
        return $tokens->map(function (PersonalAccessToken $token) use ($revocableOwnerKeys): array {
            $canRevoke = in_array((string) $token->getAttribute('tokenable_id'), $revocableOwnerKeys, true);

            return [
                ...$this->present($token),
                'canRevoke' => $canRevoke,
                'revokeReason' => $canRevoke ? null : __($this->beyondOwnRightsReason),
            ];
        })->all();
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
