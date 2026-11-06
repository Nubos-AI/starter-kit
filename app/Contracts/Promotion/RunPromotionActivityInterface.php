<?php

declare(strict_types=1);

namespace App\Contracts\Promotion;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Promotion.')]
interface RunPromotionActivityInterface
{
    #[ActivityMethod(name: 'runPromotion')]
    public function runPromotion(string $tenantId, string $actingUserId, string $promotionRunId): string;
}
