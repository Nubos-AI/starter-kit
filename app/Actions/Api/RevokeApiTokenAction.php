<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\User;

class RevokeApiTokenAction
{
    public function execute(User $serviceUser, string $tokenId): void
    {
        $serviceUser->tokens()->findOrFail($tokenId)->delete();
    }
}
