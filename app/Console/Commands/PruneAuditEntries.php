<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Maintenance\MaintenanceLockRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneAuditEntries extends Command
{
    protected $signature = 'audit:prune {--months= : Retention window in months (defaults to config audit.retention_months / 24)} {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete audit_entries older than the configured retention window in bounded batches';

    /**
     * @throws Throwable
     */
    public function handle(MaintenanceLockRegistry $maintenanceLocks): int
    {
        $months = (int) ($this->option('months') ?? config('audit.retention_months', 24));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = CarbonImmutable::now()->subMonths($months);
        $lockedTenantIds = $maintenanceLocks->lockedTenantIds();

        $deleted = 0;

        do {
            $batch = DB::transaction(function () use ($cutoff, $chunk, $lockedTenantIds): int {
                DB::statement('ALTER TABLE audit_entries DISABLE TRIGGER trg_audit_entries_append_only');

                $removed = $this->expiredRows($cutoff, $chunk, $lockedTenantIds)->delete();

                DB::statement('ALTER TABLE audit_entries ENABLE TRIGGER trg_audit_entries_append_only');

                return $removed;
            });

            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Audit retention complete — %d row(s) deleted (cutoff %s).', $deleted, $cutoff->toDateString()));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $lockedTenantIds
     */
    public function expiredRows(CarbonImmutable $cutoff, int $chunk, array $lockedTenantIds): Builder
    {
        return DB::table('audit_entries')
            ->whereIn('id', static function (Builder $query) use ($cutoff, $chunk, $lockedTenantIds): void {
                $query->select('id')
                    ->from('audit_entries')
                    ->where('changed_at', '<', $cutoff)
                    ->when(
                        $lockedTenantIds !== [],
                        static fn (Builder $entries): Builder => $entries->whereNotIn('tenant_id', $lockedTenantIds),
                    )
                    ->limit($chunk);
            });
    }
}
