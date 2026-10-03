<?php

declare(strict_types=1);

namespace App\Http\Resources\Approvals;

use App\DTOs\Approvals\ApprovalWorkloadItem;
use App\Models\User;
use App\Support\Approvals\ApprovalAnchorPresenter;
use App\Support\Approvals\ApprovalRecordContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalWorkloadItem
 */
class ApprovalProcessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovalWorkloadItem $item */
        $item = $this->resource;

        $process = $item->process;
        $stage = $item->stage;
        $deadlineAt = $stage?->deadline_at;
        $user = $request->user();
        $viewer = $user instanceof User ? $user : null;
        $anchorPresenter = app(ApprovalAnchorPresenter::class);

        return [
            'id' => (string) $process->getKey(),
            'record_id' => $process->record_id,
            'anchor_label' => $anchorPresenter->label($process, $viewer),
            'anchor_kind_label' => $anchorPresenter->kindLabel($process),
            'anchor_url' => $anchorPresenter->anchorUrl($process, $viewer),
            'transition_label' => app(ApprovalRecordContext::class)->transitionLabel($process),
            'stage_label' => $stage === null ? '—' : __('i18n.backend.http.resources.approvals.approval_process_resource.stage').$stage->position,
            'deadline_at' => $deadlineAt?->toIso8601String(),
            'is_overdue' => $deadlineAt !== null && $deadlineAt->isPast(),
            'on_behalf_of_id' => $item->onBehalfOfId,
            'on_behalf_of_label' => $item->onBehalfOfLabel,
        ];
    }
}
