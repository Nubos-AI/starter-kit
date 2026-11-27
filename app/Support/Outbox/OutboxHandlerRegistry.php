<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Support\Modules\ModuleAccess;
use Illuminate\Container\Attributes\Tag;

class OutboxHandlerRegistry
{
    /**
     * @param  iterable<IdempotentOutboxHandler>  $handlers
     */
    public function __construct(
        #[Tag(IdempotentOutboxHandler::class)] private readonly iterable $handlers,
        private readonly ?ModuleAccess $access = null,
    ) {}

    /**
     * @return list<IdempotentOutboxHandler>
     */
    public function handlersFor(string $topic, ?string $tenantId = null): array
    {
        $matching = [];

        foreach ($this->handlers as $handler) {
            if ($handler->topic() === $topic && $this->runsFor($handler, $tenantId)) {
                $matching[] = $handler;
            }
        }

        return $matching;
    }

    private function runsFor(IdempotentOutboxHandler $handler, ?string $tenantId): bool
    {
        if ($tenantId === null || $this->access === null) {
            return true;
        }

        return $this->access->allowsForTenantId($handler, $tenantId);
    }
}
