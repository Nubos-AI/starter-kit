<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Contracts\Approvals\ApprovalProcessWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\Support\Temporal\ProcessId;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

readonly class ApprovalProcessData implements TemporalSerializable
{
    public function __construct(
        public string $tenantId,
        public string $processId,
    ) {}

    public function processStart(): ProcessStartData
    {
        return new ProcessStartData(
            workflow: ApprovalProcessWorkflowInterface::class,
            workflowId: ProcessId::forTenant('approval-process', $this->tenantId, $this->processId),
            arguments: [$this],
        );
    }

    /**
     * @return array{tenant_id: string, process_id: string}
     */
    public function toTemporalPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'process_id' => $this->processId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromTemporalPayload(array $payload): self
    {
        return new self(
            tenantId: (string) $payload['tenant_id'],
            processId: (string) $payload['process_id'],
        );
    }
}
