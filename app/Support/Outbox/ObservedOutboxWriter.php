<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;

class ObservedOutboxWriter
{
    public function __construct(
        protected readonly OutboxWriter $writer,
        protected readonly OutboxHandlerRegistry $handlers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $topic, string $tenantId, OutboxSubject $subject, array $payload = []): void
    {
        if ($this->handlers->handlersFor($topic, $tenantId) === []) {
            return;
        }

        $this->writer->record($topic, $tenantId, $subject, $payload);
    }
}
