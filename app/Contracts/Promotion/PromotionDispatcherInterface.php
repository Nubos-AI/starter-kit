<?php

declare(strict_types=1);

namespace App\Contracts\Promotion;

use App\Models\PromotionRun;

interface PromotionDispatcherInterface
{
    public function start(PromotionRun $run): void;
}
