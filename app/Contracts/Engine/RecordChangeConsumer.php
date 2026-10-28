<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\RecordChangeBatch;

interface RecordChangeConsumer
{
    public function evaluateTriggers(RecordChangeBatch $changes): int;
}
