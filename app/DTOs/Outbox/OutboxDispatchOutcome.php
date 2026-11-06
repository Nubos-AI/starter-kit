<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

readonly class OutboxDispatchOutcome
{
    public function __construct(public int $handled, public int $retried, public int $failed) {}

    public function settled(): int
    {
        return $this->handled + $this->retried + $this->failed;
    }

    public function plus(self $other): self
    {
        return new self($this->handled + $other->handled, $this->retried + $other->retried, $this->failed + $other->failed);
    }
}
