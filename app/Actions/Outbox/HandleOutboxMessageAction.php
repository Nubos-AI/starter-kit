<?php

declare(strict_types=1);

namespace App\Actions\Outbox;

use App\DTOs\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxHandlerRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleOutboxMessageAction
{
    public function __construct(private readonly OutboxHandlerRegistry $handlers) {}

    /**
     * @throws Throwable
     */
    public function execute(string $handlerClass, OutboxMessage $message): void
    {
        foreach ($this->handlers->handlersFor($message->topic, $message->tenantId) as $handler) {
            if ($handler::class === $handlerClass) {
                $handler->handle($message);

                return;
            }
        }

        Log::info('Outbox handler was skipped because it is not available for the tenant of the entry.', [
            'handler' => $handlerClass,
            'topic' => $message->topic,
            'tenant_id' => $message->tenantId,
            'subject_type' => $message->subject->type,
            'subject_id' => $message->subject->id,
        ]);
    }
}
