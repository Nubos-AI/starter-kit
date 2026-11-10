<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Models\ScimToken;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RotateScimTokenAction
{
    private int $secretLength = 48;

    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    /**
     * @throws Throwable
     */
    public function execute(User $actingUser): string
    {
        $tenantId = (string) $actingUser->tenant_id;
        $prefix = (string) config('sso.scim.token_prefix');
        $plain = $prefix.Str::random($this->secretLength);

        DB::transaction(function () use ($actingUser, $tenantId, $plain): void {
            ScimToken::query()->where('scim_tokens.tenant_id', $tenantId)->delete();

            $token = ScimToken::query()->create([
                'tenant_id' => $tenantId,
                'created_by_id' => $actingUser->getKey(),
                'token_hash' => hash('sha256', $plain),
            ]);

            $this->auditor->recordEvent($token, 'scim.token_rotated', [], $tenantId);
        });

        Log::info('The scim token of a tenant was rotated.', ['tenant_id' => $tenantId, 'user_id' => $actingUser->getKey()]);

        return $plain;
    }
}
