<?php

declare(strict_types=1);

namespace App\Http\Resources\Promotion;

use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\Promotion\PromotionNotRollbackableException;
use App\Models\PromotionRun;
use App\Support\Promotion\PromotionPreviewBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionRun
 */
class PromotionRunResource extends JsonResource
{
    private string $missingAuthorityReason = 'i18n.backend.http.resources.promotion.promotion_run_resource.this_requires_permission_to_promote';

    private string $bundleCounterpartLabel = 'i18n.backend.http.resources.promotion.promotion_run_resource.configuration_package';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionRun $run */
        $run = $this->resource;
        $user = $request->user();

        $mayUpdate = $user?->can('update', $run) === true;
        $mayRollback = $user?->can('rollback', $run) === true;
        $rollbackRefusal = $this->rollbackRefusalOf($run);

        return [
            'id' => (string) $run->getKey(),
            'direction' => $run->direction->value,
            'direction_label' => $this->directionLabelOf($run->direction),
            'counterpart_label' => $this->counterpartLabelOf($run),
            'status' => $run->status->value,
            'artifact_count' => count($run->selection),
            'triggered_by_name' => $run->triggeredBy?->name,
            'created_at' => $run->created_at?->toIso8601String(),
            'can_view' => $user?->can('view', $run) === true,
            'can_update' => $mayUpdate && $run->status === PromotionRunStatus::Draft,
            'update_reason' => match (true) {
                !$mayUpdate => __($this->missingAuthorityReason),
                $run->status !== PromotionRunStatus::Draft => __(PromotionPreviewBuilder::$notDraftMessage),
                default => null,
            },
            'can_rollback' => $mayRollback && $rollbackRefusal === null,
            'rollback_reason' => $mayRollback ? $rollbackRefusal : __($this->missingAuthorityReason),
        ];
    }

    private function directionLabelOf(PromotionDirection $direction): string
    {
        return match ($direction) {
            PromotionDirection::TenantToProduction => __(config('modules.promotion.source_label', __('i18n.backend.http.resources.promotion.promotion_run_resource.source'))).' → Live',
            PromotionDirection::BundleImport => __('i18n.backend.http.resources.promotion.promotion_run_resource.configuration_package_live'),
        };
    }

    private function counterpartLabelOf(PromotionRun $run): string
    {
        if ($run->direction === PromotionDirection::BundleImport) {
            return __($this->bundleCounterpartLabel);
        }

        $label = __(config('modules.promotion.source_label', __('i18n.backend.http.resources.promotion.promotion_run_resource.source')));

        return $run->sourceTenant === null ? $label.__('i18n.backend.http.resources.promotion.promotion_run_resource.no_longer_exists') : $label;
    }

    private function rollbackRefusalOf(PromotionRun $run): ?string
    {
        return match (true) {
            $run->status === PromotionRunStatus::RolledBack => PromotionNotRollbackableException::alreadyRolledBack()->getMessage(),
            $run->status !== PromotionRunStatus::Completed && $run->status !== PromotionRunStatus::Failed => PromotionNotRollbackableException::notExecuted()->getMessage(),
            $run->snapshot_group_id === null => PromotionNotRollbackableException::withoutSnapshotGroup()->getMessage(),
            default => null,
        };
    }
}
