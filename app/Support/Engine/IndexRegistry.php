<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use App\Support\Sql\LiteralIdentifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IndexRegistry
{
    public function __construct(
        private readonly LiteralIdentifier $literal = new LiteralIdentifier,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function ensureHotFieldIndex(FieldDefinition $field): void
    {
        if ($field->is_encrypted) {
            throw new InvalidArgumentException(
                "Encrypted field \"{$field->key}\" cannot be sort/filter-indexed (R-9).",
            );
        }

        $key = $this->safeKey($field->key);
        $objectTypeId = $this->safeObjectTypeId($field->object_type_id);
        $expression = $this->indexExpression($field, $key);
        $indexName = $this->indexNameFor($field);

        $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        DB::statement(
            "CREATE INDEX {$concurrently}IF NOT EXISTS {$indexName}
                ON ".$this->recordsTable()." (tenant_id, object_type_id, {$expression})
             WHERE object_type_id = '{$objectTypeId}' AND deleted_at IS NULL",
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function ensureLocaleHotFieldIndex(FieldDefinition $field, string $locale, ?string $fallback = null): void
    {
        if ($field->is_encrypted) {
            throw new InvalidArgumentException(
                "Encrypted field \"{$field->key}\" cannot be sort-indexed (R-9).",
            );
        }

        $this->safeObjectTypeId($field->object_type_id);
        $key = $this->safeKey($field->key);
        $expression = $this->localeSortExpression($key, $locale, $fallback);
        $indexName = $this->indexNameForLocale($field, $locale, $fallback);

        $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        DB::statement(
            "CREATE INDEX {$concurrently}IF NOT EXISTS {$indexName}
                ON ".$this->recordsTable()." (tenant_id, object_type_id, {$expression}, id)
             WHERE deleted_at IS NULL",
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function ensureSearchIndex(FieldDefinition $field): void
    {
        $key = $this->safeKey($field->key);
        $objectTypeId = $this->safeObjectTypeId($field->object_type_id);
        $indexName = $this->searchIndexNameFor($field);

        $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        DB::statement(
            "CREATE INDEX {$concurrently}IF NOT EXISTS {$indexName}
                ON ".$this->recordsTable()." USING gin ((data->>'{$key}') gin_trgm_ops)
             WHERE object_type_id = '{$objectTypeId}' AND deleted_at IS NULL",
        );
    }

    public function dropSearchIndex(FieldDefinition $field): void
    {
        $this->dropIndex($this->searchIndexNameFor($field));
    }

    public function wantsSearchIndex(FieldDefinition $field): bool
    {
        return !$field->is_encrypted
            && $field->is_searchable
            && !$field->is_translatable
            && $field->field_type->isFreeTextSearchable();
    }

    public function ensureSortAndFilterIndexes(FieldDefinition $field): void
    {
        if ($this->wantsSearchIndex($field)) {
            $this->ensureSearchIndex($field);
        }

        if ($field->is_encrypted) {
            return;
        }

        if ($field->is_translatable && $field->is_sortable) {
            $fallback = $this->configuredFallbackLocale();

            foreach ($this->supportedLocales() as $locale) {
                $this->ensureLocaleHotFieldIndex($field, $locale, $fallback);
            }

            return;
        }

        if ($field->is_sortable || $field->is_filterable) {
            $this->ensureHotFieldIndex($field);
        }
    }

    public function dropHotFieldIndex(FieldDefinition $field): void
    {
        $this->dropIndex($this->indexNameFor($field));
    }

    public function dropLocaleHotFieldIndexes(FieldDefinition $field): void
    {
        $fallback = $this->configuredFallbackLocale();

        foreach ($this->supportedLocales() as $locale) {
            $this->dropIndex($this->indexNameForLocale($field, $locale, $fallback));
        }
    }

    public function syncSortAndFilterIndexes(FieldDefinition $field): void
    {
        $wantsLocaleIndexes = !$field->is_encrypted && $field->is_translatable && $field->is_sortable;
        $wantsPlainIndex = !$field->is_encrypted && !$wantsLocaleIndexes && ($field->is_sortable || $field->is_filterable);

        if (!$wantsLocaleIndexes) {
            $this->dropLocaleHotFieldIndexes($field);
        }

        if (!$wantsPlainIndex) {
            $this->dropHotFieldIndex($field);
        }

        if (!$this->wantsSearchIndex($field)) {
            $this->dropSearchIndex($field);
        }

        $this->ensureSortAndFilterIndexes($field);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function dropObjectTypeIndexes(string $objectTypeId): void
    {
        $prefix = $this->objectTypeIndexPrefix($objectTypeId);

        $indexNames = DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->where('tablename', $this->recordsTable())
            ->whereRaw('starts_with(indexname, ?)', [$prefix])
            ->orderBy('indexname')
            ->pluck('indexname');

        foreach ($indexNames as $indexName) {
            $indexName = (string) $indexName;

            if (preg_match('/^'.preg_quote($prefix, '/').'[a-z0-9_]+$/', $indexName) !== 1) {
                continue;
            }

            $this->dropIndex($indexName);
        }
    }

    private function dropIndex(string $indexName): void
    {
        $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        DB::statement("DROP INDEX {$concurrently}IF EXISTS {$indexName}");
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        $configured = config('app.supported_locales');

        if (!is_array($configured)) {
            return [];
        }

        $locales = [];

        foreach ($configured as $locale) {
            if (is_string($locale) && $locale !== '') {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    private function configuredFallbackLocale(): ?string
    {
        $fallback = config('app.fallback_locale');

        return is_string($fallback) ? $fallback : null;
    }

    private function recordsTable(): string
    {
        return (string) config('engine.records_table');
    }

    public function indexNameFor(FieldDefinition $field): string
    {
        return $this->objectTypeIndexPrefix($field->object_type_id).$this->safeKey($field->key);
    }

    private function objectTypeIndexPrefix(string $objectTypeId): string
    {
        $suffix = substr(md5($this->safeObjectTypeId($objectTypeId)), 0, 12);

        return "idx_custom_records_{$suffix}_";
    }

    public function searchIndexNameFor(FieldDefinition $field): string
    {
        return $this->indexNameFor($field).'_trgm';
    }

    public function indexNameForLocale(FieldDefinition $field, string $locale, ?string $fallback = null): string
    {
        $base = $this->indexNameFor($field);
        $suffix = $this->safeLocale($locale);

        if ($fallback !== null && $fallback !== $locale) {
            $suffix .= '_'.$this->safeLocale($fallback);
        }

        return "{$base}_{$suffix}";
    }

    /**
     * @return literal-string
     */
    public function localeSortExpression(string $key, string $locale, ?string $fallback = null): string
    {
        $key = $this->safeKey($key);
        $primary = "data->'{$key}'->>'".$this->safeLocale($locale)."'";

        if ($fallback !== null && $fallback !== $locale) {
            return "COALESCE({$primary}, data->'{$key}'->>'".$this->safeLocale($fallback)."')";
        }

        return "({$primary})";
    }

    /**
     * @return literal-string
     */
    private function safeLocale(string $locale): string
    {
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException(
                "Locale \"{$locale}\" is not a valid index identifier (must match ^[a-z]{2,3}(_[A-Z]{2})?\$).",
            );
        }

        return $this->literal->lowerSnake($locale);
    }

    /**
     * @return literal-string
     */
    public function safeKey(string $key): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(
                "Field key \"{$key}\" is not a valid index identifier (must match ^[a-z][a-z0-9_]*\$).",
            );
        }

        return $this->literal->lowerSnake($key);
    }

    private function safeObjectTypeId(string $objectTypeId): string
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $objectTypeId) !== 1) {
            throw new InvalidArgumentException(
                "Object type id \"{$objectTypeId}\" is not a valid ULID for index qualification.",
            );
        }

        return $objectTypeId;
    }

    /**
     * @return literal-string
     */
    public function sortExpression(FieldDefinition $field): string
    {
        if ($field->is_translatable) {
            $fallback = config('app.fallback_locale');

            return $this->localeSortExpression(
                $field->key,
                app()->getLocale(),
                is_string($fallback) ? $fallback : null,
            );
        }

        return $this->indexExpression($field, $this->safeKey($field->key));
    }

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException
     */
    public function timestampExpression(string $key): string
    {
        $value = "data->>'".$this->safeKey($key)."'";

        return "(CASE WHEN pg_input_is_valid({$value}, 'timestamp')"
            ." THEN ({$value})::timestamp AT TIME ZONE 'UTC' END)";
    }

    /**
     * @param  literal-string  $key
     * @return literal-string
     */
    public function indexExpression(FieldDefinition $field, string $key): string
    {
        if ($field->usesNumericIndex()) {
            return "(CASE WHEN data->>'{$key}' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'{$key}')::numeric END)";
        }

        return "(data->>'{$key}')";
    }
}
