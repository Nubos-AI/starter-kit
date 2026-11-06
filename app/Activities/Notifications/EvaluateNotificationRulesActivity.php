<?php

declare(strict_types=1);

namespace App\Activities\Notifications;

use App\Contracts\Notifications\EvaluateNotificationRulesActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Notifications\EventTriggerRuleEvaluator;

class EvaluateNotificationRulesActivity implements EvaluateNotificationRulesActivityInterface
{
    public function __construct(private readonly EventTriggerRuleEvaluator $evaluator) {}

    public function evaluateRules(RecordChangeBatch $changes): int
    {
        foreach ($changes->changes as $change) {
            $this->evaluator->evaluate(
                $change->tenantId,
                $change->objectTypeId,
                $change->recordId,
                $change->version,
                $change->changedFieldKeys,
            );
        }

        return $changes->count();
    }
}
