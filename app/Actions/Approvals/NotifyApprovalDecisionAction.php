<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\ApprovalEvent;
use App\Support\Approvals\ApprovalNotifier;
use Throwable;

class NotifyApprovalDecisionAction
{
    public function __construct(private readonly ApprovalNotifier $notifier) {}

    /**
     * @throws Throwable
     */
    public function execute(ApprovalEvent $event): void
    {
        $this->notifier->notifyDecision($event);
    }
}
