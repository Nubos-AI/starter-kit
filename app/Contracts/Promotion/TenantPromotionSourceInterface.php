<?php

declare(strict_types=1);

namespace App\Contracts\Promotion;

use App\Models\PromotionRun;
use App\Models\Tenant;
use App\Models\User;

interface TenantPromotionSourceInterface
{
    public function sourceFor(User $user): Tenant;

    public function assertReady(PromotionRun $run): void;

    public function assertContextAllowed(): void;

    public function counterpartKey(Tenant $source): string;

    public function validationKey(): string;
}
