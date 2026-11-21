<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use App\Support\Api\ApiTokenRightsGuard;
use App\Traits\Api\IssuesCreatorBoundTokens;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class RotateApiTokenAction
{
    use IssuesCreatorBoundTokens;

    public function __construct(
        private readonly ApiAbilityMap $abilityMap,
        private readonly ApiTokenRightsGuard $rightsGuard,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(User $actor, User $serviceUser, string $tokenId): NewAccessToken
    {
        return DB::transaction(function () use ($actor, $serviceUser, $tokenId): NewAccessToken {
            /** @var PersonalAccessToken $token */
            $token = $serviceUser->tokens()->findOrFail($tokenId);

            $name = $token->name;
            $abilities = array_values($token->abilities ?? []);
            $expiresAt = $token->expires_at;

            $this->rightsGuard->assertWithinOwnRights($actor, $this->abilityMap->accessForAbilities($abilities));

            $token->delete();

            return $this->issueToken($serviceUser, $actor, $name, $abilities, $expiresAt);
        });
    }
}
