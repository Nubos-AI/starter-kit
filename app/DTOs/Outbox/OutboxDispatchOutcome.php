<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class OutboxDispatchOutcome implements TemporalSerializable
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

    /**
     * @return array{handled: int, retried: int, failed: int}
     */
    public function toTemporalPayload(): array
    {
        return ['handled' => $this->handled, 'retried' => $this->retried, 'failed' => $this->failed];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return new self((int) ($payload['handled'] ?? 0), (int) ($payload['retried'] ?? 0), (int) ($payload['failed'] ?? 0));
    }
}
