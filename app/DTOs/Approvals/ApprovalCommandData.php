<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ApprovalCommandData implements TemporalSerializable
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $tenantId,
        public string $processId,
        public string $actorId,
        public array $input,
    ) {}

    /**
     * @return array{tenant_id: string, process_id: string, actor_id: string, input: array<string, mixed>}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'process_id' => $this->processId,
            'actor_id' => $this->actorId,
            'input' => $this->input,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        $input = $payload['input'] ?? [];

        return new self(
            tenantId: (string) $payload['tenant_id'],
            processId: (string) $payload['process_id'],
            actorId: (string) $payload['actor_id'],
            input: is_array($input) ? $input : [],
        );
    }
}
