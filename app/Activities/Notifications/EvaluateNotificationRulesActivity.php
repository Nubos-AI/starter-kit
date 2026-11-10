<?php

declare(strict_types=1);

namespace App\Activities\Notifications;

use App\Contracts\Notifications\EvaluateNotificationRulesActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Notifications\EventTriggerRuleEvaluator;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateNotificationRulesActivity implements EvaluateNotificationRulesActivityInterface
{
    use DescribesWorkflowExecution;

    public function __construct(private readonly EventTriggerRuleEvaluator $evaluator) {}

    /**
     * @throws Throwable
     */
    public function evaluateRules(RecordChangeBatch $changes): int
    {
        $firstFailure = null;

        foreach ($changes->changes as $change) {
            try {
                $this->evaluator->evaluate(
                    $change->tenantId,
                    $change->objectTypeId,
                    $change->recordId,
                    $change->version,
                    $change->changedFieldKeys,
                );
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;

                Log::error('Notification rules failed for a record change; the remaining changes of the batch still run.', [
                    'record_id' => $change->recordId,
                    'tenant_id' => $change->tenantId,
                    'version' => $change->version,
                    'exception' => $exception,
                    ...$this->workflowExecution(),
                ]);
            }
        }

        if ($firstFailure instanceof Throwable) {
            throw $firstFailure;
        }

        return $changes->count();
    }
}
