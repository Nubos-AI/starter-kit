<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use Illuminate\Console\Command;

class SyncLocaleIndexes extends Command
{
    protected $signature = 'engine:sync-locale-indexes';

    protected $description = 'Ensure locale-qualified sort indexes exist for every translatable, sortable field across all supported locales';

    public function handle(IndexRegistry $registry): int
    {
        $fieldCount = 0;

        FieldDefinition::withoutTenantScope()
            ->where('is_translatable', true)
            ->where('is_sortable', true)
            ->where('is_encrypted', false)
            ->each(function (FieldDefinition $field) use ($registry, &$fieldCount): void {
                $registry->ensureSortAndFilterIndexes($field);
                $fieldCount++;
            });

        $this->info(sprintf(
            'Locale sort indexes synced for %d field(s) across %d locale(s): %s.',
            $fieldCount,
            count($registry->supportedLocales()),
            implode(', ', $registry->supportedLocales()),
        ));

        return self::SUCCESS;
    }
}
