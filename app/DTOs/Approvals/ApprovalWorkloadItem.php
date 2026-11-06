<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;

readonly class ApprovalWorkloadItem
{
    /**
     * @param  list<array{value: string, label: string, description: string, avatar: array{name: string}}>  $onBehalfOfOptions
     */
    public function __construct(
        public ApprovalProcess $process,
        public ?ApprovalProcessStage $stage,
        public ?string $onBehalfOfId,
        public ?string $onBehalfOfLabel,
        public bool $canDecide,
        public ?string $decisionReason,
        public array $onBehalfOfOptions,
    ) {}
}
