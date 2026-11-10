<?php

declare(strict_types=1);

namespace App\Exceptions\Outbox;

use RuntimeException;

class OutboxPayloadTooLargeException extends RuntimeException
{
    public function __construct(public readonly string $eventId, public readonly int $bytes, public readonly int $limit)
    {
        parent::__construct('The outbox record change exceeds the relay payload limit.');
    }

    /** @return array<string, int|string> */
    public function context(): array
    {
        return ['event_id' => $this->eventId, 'bytes' => $this->bytes, 'limit' => $this->limit];
    }
}
