<?php

declare(strict_types=1);

namespace App\Contracts\Approvals;

use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use Throwable;

interface ApprovalOutcomeHandler
{
    public function anchorType(): string;

    /**
     * @throws Throwable
     */
    public function recordDecision(ApprovalProcess $process, ApprovalProcessStage $stage, ApprovalEvent $event): void;

    /**
     * @throws Throwable
     */
    public function applyApproved(ApprovalProcess $process): void;

    /**
     * @throws Throwable
     */
    public function applyRejected(ApprovalProcess $process, ?string $reason): void;
}
