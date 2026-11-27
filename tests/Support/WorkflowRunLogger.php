<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

class WorkflowRunLogger extends AbstractLogger
{
    public function __construct(private readonly WorkflowRun $run) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->run->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
