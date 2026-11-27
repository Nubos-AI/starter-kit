<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Actions\Engine\RequestRollupBackfillAction;
use App\Actions\Engine\SyncFieldIndexesAction;
use App\Contracts\Engine\FieldMaintenanceActivityInterface;
use App\Models\FieldDefinition;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class FieldMaintenanceActivity implements FieldMaintenanceActivityInterface
{
    public function __construct(
        private readonly SyncFieldIndexesAction $syncFieldIndexes,
        private readonly RequestRollupBackfillAction $requestRollupBackfill,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function syncFieldIndexes(string $tenantId, string $fieldDefinitionId): bool
    {
        return $this->steps->run(
            'fields.sync-indexes',
            ['tenant_id' => $tenantId, 'field_definition_id' => $fieldDefinitionId],
            fn (): bool => $this->tenantBinder->run($tenantId, function () use ($tenantId, $fieldDefinitionId): bool {
                $field = $this->tenantField($tenantId, $fieldDefinitionId);

                return $field instanceof FieldDefinition && $this->syncFieldIndexes->execute($field);
            }),
        );
    }

    public function requestRollupBackfill(string $tenantId, string $fieldDefinitionId): int
    {
        return $this->steps->run(
            'fields.request-rollup-backfill',
            ['tenant_id' => $tenantId, 'field_definition_id' => $fieldDefinitionId],
            fn (): int => $this->tenantBinder->run($tenantId, function () use ($tenantId, $fieldDefinitionId): int {
                $field = $this->tenantField($tenantId, $fieldDefinitionId);

                return $field instanceof FieldDefinition ? $this->requestRollupBackfill->execute($field) : 0;
            }),
        );
    }

    private function tenantField(string $tenantId, string $fieldDefinitionId): ?FieldDefinition
    {
        return FieldDefinition::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($fieldDefinitionId)
            ->first();
    }
}
