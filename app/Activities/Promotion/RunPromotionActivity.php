<?php

declare(strict_types=1);

namespace App\Activities\Promotion;

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\Contracts\Promotion\RunPromotionActivityInterface;
use App\Models\PromotionRun;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPromotionActivity implements RunPromotionActivityInterface
{
    public function __construct(
        private readonly ExecutePromotionRunAction $executor,
    ) {}

    /**
     * @throws Throwable
     */
    public function runPromotion(string $tenantId, string $actingUserId, string $promotionRunId): string
    {
        $context = [
            'tenant_id' => $tenantId,
            'promotion_run_id' => $promotionRunId,
            'acting_user_id' => $actingUserId,
        ];

        try {
            $run = PromotionRun::withoutTenantScope()
                ->whereKey($promotionRunId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $status = $this->executor->execute($run)->status->value;
        } catch (Throwable $exception) {
            Log::error('The promotion run activity failed.', [
                ...$context,
                'exception_class' => $exception::class,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $status;
    }
}
