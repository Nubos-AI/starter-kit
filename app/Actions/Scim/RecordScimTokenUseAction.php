<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Models\ScimToken;

class RecordScimTokenUseAction
{
    private int $resolutionSeconds = 60;

    public function execute(ScimToken $token): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(now()->subSeconds($this->resolutionSeconds))) {
            return;
        }

        ScimToken::withoutTenantScope()->whereKey($token->getKey())->update(['last_used_at' => now()]);
    }
}
