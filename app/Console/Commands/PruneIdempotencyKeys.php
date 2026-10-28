<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--hours= : Retention window in hours (defaults to config api.idempotency.retention_hours / 24)} {--chunk=1000 : Rows deleted per batch}';

    protected $description = 'Delete idempotency_keys older than the configured retention window in bounded batches';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('api.idempotency.retention_hours', 24));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $deleted = 0;

        do {
            $batch = DB::table('idempotency_keys')
                ->whereIn('id', function ($query) use ($cutoff, $chunk): void {
                    $query->select('id')
                        ->from('idempotency_keys')
                        ->where('created_at', '<', $cutoff)
                        ->limit($chunk);
                })
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Idempotency retention complete — %d row(s) deleted (cutoff %s).', $deleted, $cutoff->toDateTimeString()));

        return self::SUCCESS;
    }
}
