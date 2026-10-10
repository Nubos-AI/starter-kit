<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\RelayOutboxActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Engine\OutboxRelay;

class RelayOutboxActivity implements RelayOutboxActivityInterface
{
    public function __construct(private readonly OutboxRelay $relay) {}

    public function publishOutbox(): RecordChangeBatch
    {
        return RecordChangeBatch::fromArray($this->relay->publishAll());
    }
}
