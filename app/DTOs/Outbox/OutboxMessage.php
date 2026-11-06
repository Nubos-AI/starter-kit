<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $topic,
        public string $tenantId,
        public OutboxSubject $subject,
        public array $payload,
        public int $attempts,
    ) {}
}
