<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\RecordChangeBatch;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'AutomationDispatch.')]
interface EvaluateRecordConsumersActivityInterface
{
    #[ActivityMethod(name: 'evaluateTriggers')]
    public function evaluateTriggers(RecordChangeBatch $changes): int;
}
