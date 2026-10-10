<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use Illuminate\Console\Command;

class SyncSearchIndexes extends Command
{
    protected $signature = 'engine:sync-search-indexes';

    protected $description = 'Ensure a trigram index exists for every searchable free-text field, and drop the ones no longer wanted';

    public function handle(IndexRegistry $registry): int
    {
        $created = 0;
        $dropped = 0;

        FieldDefinition::withoutTenantScope()->each(
            function (FieldDefinition $field) use ($registry, &$created, &$dropped): void {
                if ($registry->wantsSearchIndex($field)) {
                    $registry->ensureSearchIndex($field);
                    $created++;

                    return;
                }

                $registry->dropSearchIndex($field);
                $dropped++;
            },
        );

        $this->info(sprintf(
            'Trigram search indexes ensured for %d field(s), removed for %d field(s).',
            $created,
            $dropped,
        ));

        return self::SUCCESS;
    }
}
