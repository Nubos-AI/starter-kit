<?php

declare(strict_types=1);

namespace App\Contracts\Outbox;

use App\DTOs\Outbox\OutboxSubject;

interface OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $topic, string $tenantId, OutboxSubject $subject, array $payload = []): void;
}
