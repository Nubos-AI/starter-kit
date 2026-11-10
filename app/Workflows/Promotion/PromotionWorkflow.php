<?php

declare(strict_types=1);

namespace App\Workflows\Promotion;

use App\Contracts\Promotion\PromotionWorkflowInterface;
use App\Contracts\Promotion\RunPromotionActivityInterface;
use App\DTOs\Promotion\PromotionRunData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class PromotionWorkflow implements PromotionWorkflowInterface
{
    public function run(PromotionRunData $input): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::hours(6))
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))
            ->build(RunPromotionActivityInterface::class);

        try {
            $status = yield $activity->runPromotion($input->tenantId, $input->actingUserId, $input->promotionRunId);
        } catch (ActivityFailure $failure) {
            $finalize = BoundedActivityOptions::make(CarbonInterval::minutes(5))
                ->build(RunPromotionActivityInterface::class);

            $markedFailed = yield $finalize->failAbandonedPromotion($input->tenantId, $input->promotionRunId);

            Workflow::getLogger()->error('Promotion workflow failed.', [
                ...$this->logContext($input),
                'run_marked_failed' => $markedFailed === true,
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('Promotion workflow finished.', [
            ...$this->logContext($input),
            'status' => $status,
        ]);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, promotion_run_id: string}
     */
    private function logContext(PromotionRunData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'promotion_run_id' => $input->promotionRunId,
        ];
    }
}
