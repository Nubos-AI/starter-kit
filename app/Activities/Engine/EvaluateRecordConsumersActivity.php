<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\EvaluateRecordConsumersActivityInterface;
use App\Contracts\Engine\RecordChangeConsumer;
use App\DTOs\Engine\RecordChange;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Modules\ModuleAccess;
use Illuminate\Container\Attributes\Tag;

class EvaluateRecordConsumersActivity implements EvaluateRecordConsumersActivityInterface
{
    /** @param iterable<RecordChangeConsumer> $consumers */
    public function __construct(
        #[Tag(RecordChangeConsumer::class)] private readonly iterable $consumers,
        private readonly ?ModuleAccess $access = null,
    ) {}

    public function evaluateTriggers(RecordChangeBatch $changes): int
    {
        foreach ($this->consumers as $consumer) {
            $available = $this->availableTo($consumer, $changes);

            if ($available->isEmpty() && !$changes->isEmpty()) {
                continue;
            }

            $consumer->evaluateTriggers($available);
        }

        return $changes->count();
    }

    private function availableTo(RecordChangeConsumer $consumer, RecordChangeBatch $changes): RecordChangeBatch
    {
        $access = $this->access;

        if ($access === null) {
            return $changes;
        }

        $available = array_values(array_filter(
            $changes->changes,
            static fn (RecordChange $change): bool => $access->allowsForTenantId($consumer, $change->tenantId),
        ));

        return count($available) === $changes->count() ? $changes : new RecordChangeBatch($available, $changes->relayBatchId);
    }
}
