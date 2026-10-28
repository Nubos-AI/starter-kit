<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use Illuminate\Container\Attributes\Tag;

class OutboxHandlerRegistry
{
    /**
     * @param  iterable<IdempotentOutboxHandler>  $handlers
     */
    public function __construct(#[Tag(IdempotentOutboxHandler::class)] private readonly iterable $handlers) {}

    /**
     * @return list<IdempotentOutboxHandler>
     */
    public function handlersFor(string $topic): array
    {
        $matching = [];

        foreach ($this->handlers as $handler) {
            if ($handler->topic() === $topic) {
                $matching[] = $handler;
            }
        }

        return $matching;
    }
}
