<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Enums\Promotion\PromotionRunStatus;
use App\Models\PromotionRun;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailAbandonedPromotionRunAction
{
    private string $abandonedMessage = 'i18n.backend.actions.promotion.fail_abandoned_promotion_run_action.the_promotion_was_interrupted_before_it_could_finish';

    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    /**
     * @throws Throwable
     */
    public function execute(string $tenantId, string $promotionRunId): bool
    {
        $marked = DB::transaction(function () use ($tenantId, $promotionRunId): bool {
            $released = PromotionRun::withoutTenantScope()
                ->whereKey($promotionRunId)
                ->where('tenant_id', $tenantId)
                ->where('status', PromotionRunStatus::Applying)
                ->update([
                    'status' => PromotionRunStatus::Failed,
                    'finished_at' => now(),
                ]);

            if ($released === 0) {
                return false;
            }

            $run = PromotionRun::withoutTenantScope()
                ->whereKey($promotionRunId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $run->fill(['report' => ['error' => __($this->abandonedMessage)]])->save();

            $this->auditor->recordEvent($run, 'operation.promotion_failed', [
                'error' => __($this->abandonedMessage),
                'exceptionClass' => null,
                'triggeredById' => $run->triggered_by_id,
                'snapshotGroupId' => $run->snapshot_group_id,
            ], $tenantId);

            return true;
        });

        $context = [
            'tenant_id' => $tenantId,
            'promotion_run_id' => $promotionRunId,
        ];

        if ($marked) {
            Log::error('An abandoned promotion run was marked failed.', $context);
        } else {
            Log::info('The abandoned promotion run had already left the applying state.', $context);
        }

        return $marked;
    }
}
