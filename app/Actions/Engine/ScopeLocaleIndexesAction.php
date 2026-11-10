<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use Illuminate\Support\Facades\DB;
use stdClass;

class ScopeLocaleIndexesAction
{
    public function execute(): void
    {
        $indexes = DB::select(<<<'SQL'
            SELECT DISTINCT p.indexname, p.indexdef, f.object_type_id
            FROM pg_indexes p
            JOIN field_definitions f ON starts_with(
                p.indexname,
                'idx_custom_records_' || substr(md5(f.object_type_id::text), 1, 12) || '_' || f.key || '_'
            )
            WHERE p.schemaname = current_schema()
                AND p.tablename = 'custom_records'
                AND f.is_translatable = true
                AND substring(p.indexname FROM length(
                    'idx_custom_records_' || substr(md5(f.object_type_id::text), 1, 12) || '_' || f.key || '_'
                ) + 1) ~ '^[a-z]{2,3}(_[a-z]{2,3}){0,3}$'
                AND position(quote_literal(f.key::text) || '::text' IN p.indexdef) > 0
            SQL);

        foreach ($indexes as $index) {
            $this->replace($index);
        }
    }

    private function replace(stdClass $index): void
    {
        $name = (string) $index->indexname;
        $objectTypeId = (string) $index->object_type_id;
        $definition = (string) $index->indexdef;

        if (preg_match('/^idx_custom_records_[a-z0-9_]+$/', $name) !== 1
            || preg_match('/^[0-9A-Za-z]{26}$/', $objectTypeId) !== 1) {
            return;
        }

        $replacement = 'idx_locale_new_'.md5($name);
        $previous = 'idx_locale_old_'.md5($name);

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$previous}");

        $isScoped = str_contains($definition, "object_type_id = '{$objectTypeId}'");

        if ($isScoped) {
            return;
        }

        $parts = explode(' WHERE ', $definition, 2);

        if (count($parts) !== 2 || !str_contains($parts[1], 'deleted_at IS NULL')) {
            return;
        }

        $base = preg_replace('/^CREATE INDEX '.preg_quote($name, '/').' /', "CREATE INDEX CONCURRENTLY {$replacement} ", $parts[0]);

        if (!is_string($base) || $base === $parts[0]) {
            return;
        }

        $predicate = "object_type_id = '{$objectTypeId}' AND deleted_at IS NULL";

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$replacement}");
        DB::statement("{$base} WHERE {$predicate}");

        DB::transaction(function () use ($name, $replacement, $previous): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("ALTER INDEX {$name} RENAME TO {$previous}");
            DB::statement("ALTER INDEX {$replacement} RENAME TO {$name}");
        });

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$previous}");
    }
}
