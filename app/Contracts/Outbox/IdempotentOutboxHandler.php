<?php

declare(strict_types=1);

namespace App\Contracts\Outbox;

use App\DTOs\Outbox\OutboxMessage;
use Throwable;

interface IdempotentOutboxHandler
{
    public function topic(): string;

    /**
     * @throws Throwable
     */
    public function handle(OutboxMessage $message): void;
}
