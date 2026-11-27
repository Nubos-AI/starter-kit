<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalNotifier;
use Throwable;

class NotifyApprovalEscalationAction
{
    public function __construct(private readonly ApprovalNotifier $notifier) {}

    /**
     * @throws Throwable
     */
    public function execute(ApprovalProcessStage $stage, ApprovalEscalationType $type): void
    {
        $this->notifier->notifyEscalation($stage, $type);
    }
}
