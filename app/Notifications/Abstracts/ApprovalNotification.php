<?php

declare(strict_types=1);

namespace App\Notifications\Abstracts;

use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalAnchorPresenter;
use App\Support\Approvals\ApprovalSubjectSource;
use Carbon\CarbonInterface;

abstract class ApprovalNotification extends EngineNotification
{
    public function __construct(protected readonly ApprovalProcess $process) {}

    protected static function processOf(ApprovalProcessStage|ApprovalEvent $subject): ApprovalProcess
    {
        return app(ApprovalSubjectSource::class)->processWithRecord($subject);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $title, string $body, mixed $notifiable): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'approvalProcessId' => (string) $this->process->getKey(),
            'recordId' => $this->process->record_id,
            'recordNumber' => $this->process->record?->record_number,
            'url' => $this->presenter()->targetUrl($this->process, $notifiable),
        ];
    }

    protected function presenter(): ApprovalAnchorPresenter
    {
        return app(ApprovalAnchorPresenter::class);
    }

    protected function subjects(): ApprovalSubjectSource
    {
        return app(ApprovalSubjectSource::class);
    }

    protected function deadlineLabel(?CarbonInterface $deadline): ?string
    {
        return $deadline?->timezone('UTC')->format('d.m.Y H:i');
    }
}
