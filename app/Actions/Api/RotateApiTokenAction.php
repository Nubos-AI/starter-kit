<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class RotateApiTokenAction
{
    /**
     * @throws Throwable
     */
    public function execute(User $serviceUser, string $tokenId): NewAccessToken
    {
        return DB::transaction(function () use ($serviceUser, $tokenId): NewAccessToken {
            /** @var PersonalAccessToken $token */
            $token = $serviceUser->tokens()->findOrFail($tokenId);

            $name = $token->name;
            $abilities = $token->abilities ?? [];
            $expiresAt = $token->expires_at;

            $token->delete();

            return $serviceUser->createToken($name, $abilities, $expiresAt);
        });
    }
}
