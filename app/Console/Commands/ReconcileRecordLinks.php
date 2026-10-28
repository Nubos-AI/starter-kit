<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReconcileRecordLinks extends Command
{
    private int $chunkSize = 1000;

    protected $signature = 'records:reconcile-links {--dry-run : Report orphaned edges without deleting them}';

    protected $description = 'Delete record links whose endpoint row no longer exists';

    public function handle(ObjectTypeBackingRegistry $backings, MaintenanceLockRegistry $maintenanceLocks): int
    {
        $orphans = 0;
        $lockedTenantIds = $maintenanceLocks->lockedTenantIds();

        foreach ($lockedTenantIds as $lockedTenantId) {
        }

        $types = ObjectType::query()
            ->withoutGlobalScopes()
            ->when(
                $lockedTenantIds !== [],
                static fn (Builder $query): Builder => $query->whereNotIn('tenant_id', $lockedTenantIds),
            );

        foreach ($types->cursor() as $type) {
            $orphans += $this->reconcile($backings, $type, 'from_record_type', 'from_record_id');
            $orphans += $this->reconcile($backings, $type, 'to_record_type', 'to_record_id');
        }

        $this->info($this->option('dry-run')
            ? "Found {$orphans} orphaned record link(s)."
            : "Removed {$orphans} orphaned record link(s).");

        return self::SUCCESS;
    }

    private function reconcile(
        ObjectTypeBackingRegistry $backings,
        ObjectType $type,
        string $typeColumn,
        string $idColumn,
    ): int {
        $backing = $backings->for($type);
        $orphans = 0;

        $this->endpointIds($type, $typeColumn, $idColumn)->each(
            function (Collection $ids) use ($backing, $type, $typeColumn, $idColumn, &$orphans): void {
                $existing = $backing->newQuery($type)
                    ->withoutGlobalScopes()
                    ->whereKey($ids->all())
                    ->pluck($backing->keyName());

                $missing = $ids->diff($existing);

                if ($missing->isEmpty()) {
                    return;
                }

                $orphans += $missing->count();

                if (!$this->option('dry-run')) {
                    RecordLink::query()
                        ->withoutGlobalScopes()
                        ->where($typeColumn, $type->getKey())
                        ->whereIn($idColumn, $missing->all())
                        ->delete();
                }
            },
        );

        return $orphans;
    }

    /**
     * @return Collection<int, Collection<int, string>>
     */
    private function endpointIds(ObjectType $type, string $typeColumn, string $idColumn): Collection
    {
        /** @var Collection<int, string> $ids */
        $ids = RecordLink::query()
            ->withoutGlobalScopes()
            ->where($typeColumn, $type->getKey())
            ->distinct()
            ->pluck($idColumn)
            ->map(static fn (mixed $id): string => (string) $id);

        return $ids->chunk($this->chunkSize);
    }
}
