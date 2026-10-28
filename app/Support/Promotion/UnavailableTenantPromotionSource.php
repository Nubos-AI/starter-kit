<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\Tenant;
use App\Models\User;

class UnavailableTenantPromotionSource implements TenantPromotionSourceInterface
{
    public function sourceFor(User $user): Tenant
    {
        throw PromotionSourceUnavailableException::moduleMissing();
    }

    public function assertReady(PromotionRun $run): void
    {
        throw PromotionSourceUnavailableException::moduleMissing();
    }

    public function assertContextAllowed(): void
    {
        throw PromotionSourceUnavailableException::moduleMissing();
    }

    public function counterpartKey(Tenant $source): string
    {
        throw PromotionSourceUnavailableException::moduleMissing();
    }

    public function validationKey(): string
    {
        return 'source';
    }
}
