<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalNotifier;
use Throwable;

class NotifyApprovalStageStartedAction
{
    public function __construct(private readonly ApprovalNotifier $notifier) {}

    /**
     * @throws Throwable
     */
    public function execute(ApprovalProcessStage $stage): void
    {
        $this->notifier->notifyStageStarted($stage);
    }
}
