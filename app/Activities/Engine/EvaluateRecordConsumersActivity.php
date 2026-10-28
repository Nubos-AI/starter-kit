<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\EvaluateRecordConsumersActivityInterface;
use App\Contracts\Engine\RecordChangeConsumer;
use App\DTOs\Engine\RecordChangeBatch;
use Illuminate\Container\Attributes\Tag;

class EvaluateRecordConsumersActivity implements EvaluateRecordConsumersActivityInterface
{
    /** @param iterable<RecordChangeConsumer> $consumers */
    public function __construct(#[Tag(RecordChangeConsumer::class)] private readonly iterable $consumers) {}

    public function evaluateTriggers(RecordChangeBatch $changes): int
    {
        foreach ($this->consumers as $consumer) {
            $consumer->evaluateTriggers($changes);
        }

        return $changes->count();
    }
}
