<?php

declare(strict_types=1);

namespace App\Workflows\Promotion;

use App\Contracts\Promotion\PromotionWorkflowInterface;
use App\Contracts\Promotion\RunPromotionActivityInterface;
use App\DTOs\Promotion\PromotionRunData;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;

class PromotionWorkflow implements PromotionWorkflowInterface
{
    public function run(PromotionRunData $input): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::hours(6))
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))
            ->build(RunPromotionActivityInterface::class);

        yield $activity->runPromotion($input->tenantId, $input->actingUserId, $input->promotionRunId);
    }
}
