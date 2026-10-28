<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomRecord;
use App\Support\Approvals\ApprovalInvalidator;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApprovalCancellationObserver
{
    public function __construct(private readonly ApprovalInvalidator $approvalInvalidator) {}

    /**
     * @throws Throwable
     */
    public function deleting(CustomRecord $record): void
    {
        if ($record->isForceDeleting()) {
            return;
        }

        $reason = $record->merged_into_record_id === null
            ? __('i18n.backend.observers.approval_cancellation_observer.record_deleted')
            : __('i18n.backend.observers.approval_cancellation_observer.record_merged_into_another_record');

        DB::transaction(fn () => $this->approvalInvalidator->cancelOpen($record, $reason));
    }
}
