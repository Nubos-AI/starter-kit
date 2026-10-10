<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Engine\StartRecordBackfillAction;
use App\Enums\Engine\RecordBackfillKind;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\ObjectType;
use App\Models\Tenant;
use Illuminate\Console\Command;

class BackfillRecords extends Command
{
    protected $signature = 'records:backfill
        {kind : The registered backfill kind}
        {objectType? : Restrict the backfill to a single object type id}
        {--tenant= : Restrict the backfill to a single tenant id}';

    protected $description = 'Start the record backfill workflow for every tenant and object type in scope';

    public function handle(StartRecordBackfillAction $action): int
    {
        $kind = RecordBackfillKind::tryFrom($this->argument('kind'));

        if (!$kind instanceof RecordBackfillKind) {
            $this->error("Unknown backfill kind [{$this->argument('kind')}].");

            return self::FAILURE;
        }

        $requestedObjectTypeId = $this->requestedObjectTypeId();
        $openedRuns = 0;
        $refusedTenants = 0;

        foreach ($this->tenantIds() as $tenantId) {
            try {
                $openedRuns += $this->startForTenant($action, $tenantId, $kind, $requestedObjectTypeId);
            } catch (TenantUnderMaintenanceException $exception) {
                $refusedTenants++;

                $this->error("Mandant {$tenantId}: {$exception->getMessage()}");
            }
        }

        if ($refusedTenants > 0) {
            return self::FAILURE;
        }

        if ($requestedObjectTypeId !== null && $openedRuns === 0) {
            $this->error("Object type [{$requestedObjectTypeId}] belongs to none of the tenants in scope.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @throws TenantUnderMaintenanceException
     */
    private function startForTenant(StartRecordBackfillAction $action, string $tenantId, RecordBackfillKind $kind, ?string $requestedObjectTypeId): int
    {
        $openedRuns = 0;

        foreach ($this->objectTypeIds($tenantId, $requestedObjectTypeId) as $objectTypeId) {
            $backfillRunId = $action->execute($tenantId, $kind, $objectTypeId);
            $openedRuns++;

            $this->info("Opened backfill run {$backfillRunId} for tenant {$tenantId} and object type {$objectTypeId}.");
        }

        return $openedRuns;
    }

    /**
     * @return list<string>
     */
    private function tenantIds(): array
    {
        $tenantId = $this->option('tenant');

        if (is_string($tenantId) && $tenantId !== '') {
            return [$tenantId];
        }

        /** @var list<string> $ids */
        $ids = Tenant::query()->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function objectTypeIds(string $tenantId, ?string $requestedObjectTypeId): array
    {
        $query = ObjectType::withoutTenantScope()->where('tenant_id', $tenantId);

        if ($requestedObjectTypeId !== null) {
            $query->withTrashed()->whereKey($requestedObjectTypeId);
        }

        /** @var list<string> $ids */
        $ids = $query->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    private function requestedObjectTypeId(): ?string
    {
        $objectTypeId = $this->argument('objectType');

        return is_string($objectTypeId) && $objectTypeId !== '' ? $objectTypeId : null;
    }
}
