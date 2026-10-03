<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\OutboxEvent;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxRelay
{
    public function __construct(private readonly MaintenanceLockRegistry $maintenanceLocks) {}

    /**
     * @return list<array{tenant_id: string, object_type_id: string, record_id: string, version: int, sequence: int, changed_field_keys: array<int, string>, triggered_by_automation_id: string|null, root_run_id: string|null}>
     */
    public function publishAll(): array
    {
        $batchSize = (int) config('engine.outbox.batch_size');
        $published = [];

        do {
            $batch = $this->publishBatch($batchSize);
            $published = array_merge($published, $batch);
        } while (count($batch) === $batchSize);

        return $published;
    }

    /**
     * @return list<array{tenant_id: string, object_type_id: string, record_id: string, version: int, sequence: int, changed_field_keys: array<int, string>, triggered_by_automation_id: string|null, root_run_id: string|null}>
     *
     * @throws Throwable
     */
    public function publishBatch(int $batchSize): array
    {
        $events = DB::transaction(function () use ($batchSize): Collection {
            $claimed = $this->claimQuery($this->maintenanceLocks->lockedTenantIds(), $batchSize)->get();

            if ($claimed->isNotEmpty()) {
                OutboxEvent::query()
                    ->whereKey($claimed->modelKeys())
                    ->update(['published_at' => now()]);
            }

            return $claimed;
        });

        $published = [];

        foreach ($events as $event) {
            $published[] = [
                'tenant_id' => $event->tenant_id,
                'object_type_id' => $event->object_type_id,
                'record_id' => $event->record_id,
                'version' => $event->version,
                'sequence' => $event->sequence,
                'changed_field_keys' => $event->changed_field_keys,
                'triggered_by_automation_id' => $event->triggered_by_automation_id,
                'root_run_id' => $event->root_run_id,
            ];
        }

        return $published;
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Builder<OutboxEvent>
     */
    public function claimQuery(array $lockedTenantIds, int $batchSize): Builder
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->when(
                $lockedTenantIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('tenant_id', $lockedTenantIds),
            )
            ->orderBy('sequence')
            ->limit($batchSize)
            ->lock('for update skip locked');
    }
}
