<?php

declare(strict_types=1);

namespace App\Traits\Api;

use App\Models\User;
use DateTimeInterface;
use Laravel\Sanctum\NewAccessToken;

trait IssuesCreatorBoundTokens
{
    /**
     * @param  list<string>  $abilities
     */
    private function issueToken(User $holder, User $creator, string $name, array $abilities, ?DateTimeInterface $expiresAt): NewAccessToken
    {
        $token = $holder->createToken($name, $abilities, $expiresAt);

        if ($holder->is_service) {
            $token->accessToken->forceFill(['creator_id' => $creator->getKey()])->save();
        }

        return $token;
    }
}
