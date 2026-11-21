<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\Tenancy\DomainProofPurpose;

class DomainProof
{
    /**
     * @return array{name: string, value: string}
     */
    public static function recordFor(DomainProofPurpose $purpose, string $tenantId, string $domain, string $challenge = ''): array
    {
        $subject = $challenge === ''
            ? "{$purpose->value}-domain:{$tenantId}:{$domain}"
            : "{$purpose->value}-domain:{$tenantId}:{$domain}:{$challenge}";
        $token = hash_hmac('sha256', $subject, (string) config('app.key'));

        return ['name' => "_nubos-{$purpose->value}.{$domain}", 'value' => "nubos-{$purpose->value}-verification={$token}"];
    }
}
