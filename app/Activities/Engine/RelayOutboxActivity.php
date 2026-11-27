<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\RelayOutboxActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Engine\OutboxRelay;
use LogicException;
use Temporal\Activity;

class RelayOutboxActivity implements RelayOutboxActivityInterface
{
    public function __construct(private readonly OutboxRelay $relay) {}

    public function publishOutbox(): RecordChangeBatch
    {
        $info = Activity::getInfo();
        $execution = $info->workflowExecution ?? throw new LogicException('The outbox relay requires a workflow execution.');
        $batchId = $execution->getRunID().':'.$info->id;

        return $this->relay->claim($batchId);
    }

    public function acknowledgeOutbox(string $batchId, int $expectedCount): void
    {
        $this->relay->acknowledge($batchId, $expectedCount);
    }

    public function renewOutbox(string $batchId, int $expectedCount): bool
    {
        return $this->relay->renew($batchId, $expectedCount);
    }
}
