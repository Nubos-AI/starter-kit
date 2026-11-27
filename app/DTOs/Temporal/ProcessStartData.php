<?php

declare(strict_types=1);

namespace App\DTOs\Temporal;

use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

readonly class ProcessStartData
{
    /**
     * @param  class-string  $workflow
     * @param  non-empty-string  $workflowId
     * @param  list<mixed>  $arguments
     */
    public function __construct(
        public string $workflow,
        public string $workflowId,
        public array $arguments = [],
        public IdReusePolicy $reusePolicy = IdReusePolicy::AllowDuplicate,
        public ?WorkflowIdConflictPolicy $conflictPolicy = null,
        public ?int $executionTimeoutSeconds = null,
        public ?string $submissionField = null,
    ) {}
}
