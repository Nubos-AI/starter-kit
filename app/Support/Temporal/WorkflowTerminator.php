<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;

class WorkflowTerminator
{
    public function __construct(private readonly WorkflowClientInterface $workflows) {}

    /** @param non-empty-string $workflowId
     * @param  non-empty-string|null  $runId
     */
    public function terminate(string $workflowId, string $reason, ?string $runId = null): void
    {
        try {
            $this->workflows->newUntypedRunningWorkflowStub($workflowId, $runId)->terminate($reason);
        } catch (WorkflowNotFoundException) {
            return;
        } catch (ServiceClientException $exception) {
            if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                throw $exception;
            }
        }
    }
}
