<?php

declare(strict_types=1);

namespace App\DTOs\Outbox;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class OutboxDelivery implements TemporalSerializable
{
    /**
     * @param  list<string>  $entryIds
     * @param  list<string>  $handlerClasses
     */
    public function __construct(
        public array $entryIds,
        public OutboxMessage $message,
        public array $handlerClasses,
    ) {}

    /**
     * @return array{entry_ids: list<string>, message: array<string, mixed>, handler_classes: list<string>}
     */
    public function toTemporalPayload(): array
    {
        return [
            'entry_ids' => $this->entryIds,
            'message' => $this->message->toTemporalPayload(),
            'handler_classes' => $this->handlerClasses,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        /** @var array<string, mixed> $message */
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];

        return new self(
            entryIds: self::strings($payload['entry_ids'] ?? []),
            message: OutboxMessage::fromTemporalPayload($message),
            handlerClasses: self::strings($payload['handler_classes'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_map(strval(...), array_filter(is_array($values) ? $values : [], is_scalar(...))));
    }
}
