<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\ApprovalProcess;
use App\Models\CustomRecord;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Engine\RecordTitleResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

class ApprovalAnchorPresenter
{
    public static string $fallbackKindLabel = 'i18n.backend.support.approvals.approval_anchor_presenter.approval_process';

    private string $missingKindLabel = '—';

    public function label(ApprovalProcess $process, ?User $viewer): string
    {
        if ($process->record_id === null || !$viewer instanceof User) {
            return $this->referenceLabel($process);
        }

        $record = $this->recordOf($process);

        if (!$record instanceof CustomRecord) {
            return $process->record_id;
        }

        if (Gate::forUser($viewer)->denies('view', $record)) {
            return $this->recordReference($record);
        }

        return RecordTitleResolver::forRequest()->titleFor($viewer, $record);
    }

    public function referenceLabel(ApprovalProcess $process): string
    {
        if ($process->record_id !== null) {
            $record = $this->recordOf($process);

            return $record instanceof CustomRecord
                ? $this->recordReference($record)
                : $process->record_id;
        }

        $kind = ApprovalAnchorKind::forModelClass($process->anchor_type);

        if ($kind instanceof ApprovalAnchorKind) {
            $anchor = $this->anchorOf($process);

            return $anchor !== null
                ? $this->datedLabel($kind->label(), $anchor->created_at)
                : __('i18n.backend.support.approvals.approval_anchor_presenter.no_longer_exists', ['value1' => $kind->label()]);
        }

        return $this->datedLabel(__(self::$fallbackKindLabel), $process->started_at);
    }

    public function kindLabel(ApprovalProcess $process): string
    {
        if ($process->record_id !== null) {
            return $this->recordOf($process)?->objectType->name ?? $this->missingKindLabel;
        }

        return ApprovalAnchorKind::forModelClass($process->anchor_type)?->label() ?? __(self::$fallbackKindLabel);
    }

    public function anchorUrl(ApprovalProcess $process, ?User $viewer): ?string
    {
        if ($process->record_id !== null) {
            return route('engine.records.show', ['record' => $process->record_id]);
        }

        if (!$viewer instanceof User) {
            return null;
        }

        $anchor = $this->anchorOf($process);

        if ($anchor === null || Gate::forUser($viewer)->denies('view', $anchor)) {
            return null;
        }

        return route('engine.promotions.show', ['promotionRun' => $anchor->getKey()]);
    }

    public function processReference(ApprovalProcess $process): string
    {
        $referenceLabel = $this->referenceLabel($process);

        return $process->record_id === null
            ? "„{$referenceLabel}\""
            : "zum Datensatz {$referenceLabel}";
    }

    public function targetUrl(ApprovalProcess $process, mixed $recipient): string
    {
        return $this->anchorUrl($process, $recipient instanceof User ? $recipient : null)
            ?? route('engine.approvals.show', ['approval' => $process->getKey()]);
    }

    private function recordOf(ApprovalProcess $process): ?CustomRecord
    {
        if ($process->relationLoaded('record')) {
            return $process->record;
        }

        return $process->record()->withTrashed()->first();
    }

    private function recordReference(CustomRecord $record): string
    {
        return (string) ($record->record_number ?? $record->getKey());
    }

    private function anchorOf(ApprovalProcess $process): ?PromotionRun
    {
        $kind = ApprovalAnchorKind::forModelClass($process->anchor_type);
        $anchor = $kind instanceof ApprovalAnchorKind ? $process->anchor : null;

        return $anchor instanceof PromotionRun ? $anchor : null;
    }

    private function datedLabel(string $kindLabel, ?CarbonInterface $at): string
    {
        if (!$at instanceof CarbonInterface) {
            return $kindLabel;
        }

        return "{$kindLabel} vom {$at->copy()->timezone('UTC')->format('d.m.Y H:i')} UTC";
    }
}
