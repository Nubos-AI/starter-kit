<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Exceptions\Engine\IndexCreationFailedException;
use App\Models\FieldDefinition;
use App\Support\Sql\LiteralIdentifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $this->createIndex(
            $field,
            $indexName,
            'ON '.$this->recordsTable()." (tenant_id, object_type_id, {$expression})
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

        $objectTypeId = $this->safeObjectTypeId($field->object_type_id);
        $key = $this->safeKey($field->key);
        $expression = $this->localeSortExpression($key, $locale, $fallback);
        $indexName = $this->indexNameForLocale($field, $locale, $fallback);

        $this->createIndex(
            $field,
            $indexName,
            'ON '.$this->recordsTable()." (tenant_id, object_type_id, {$expression}, id)
             WHERE object_type_id = '{$objectTypeId}' AND deleted_at IS NULL",
        );
    }

    public function wantsLocaleHotFieldIndexes(FieldDefinition $field): bool
    {
        return !$field->is_encrypted && $field->is_translatable && $field->is_sortable;
    }

    public function wantsHotFieldIndex(FieldDefinition $field): bool
    {
        return !$field->is_encrypted
            && !$this->wantsLocaleHotFieldIndexes($field)
            && ($field->is_sortable || ($field->is_filterable && $field->needsFilterIndex()));
    }

    public function ensureSortAndFilterIndexes(FieldDefinition $field): void
    {
        if ($this->wantsLocaleHotFieldIndexes($field)) {
            $fallback = $this->configuredFallbackLocale();

            foreach ($this->supportedLocales() as $locale) {
                $this->ensureLocaleHotFieldIndex($field, $locale, $fallback);
            }
        }

        if ($this->wantsHotFieldIndex($field)) {
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
        if (!$this->wantsLocaleHotFieldIndexes($field)) {
            $this->dropLocaleHotFieldIndexes($field);
        }

        if (!$this->wantsHotFieldIndex($field)) {
            $this->dropHotFieldIndex($field);
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

    /**
     * @throws IndexCreationFailedException
     */
    private function createIndex(FieldDefinition $field, string $indexName, string $definition): void
    {
        $isConcurrent = DB::transactionLevel() === 0;
        $concurrently = $isConcurrent ? 'CONCURRENTLY ' : '';

        try {
            DB::statement("CREATE INDEX {$concurrently}IF NOT EXISTS {$indexName}
                {$definition}");
        } catch (QueryException $exception) {
            $context = [
                'index_name' => $indexName,
                'object_type_id' => $field->object_type_id,
                'field_key' => $field->key,
                'concurrently' => $isConcurrent,
                'sql_state' => $exception->getCode(),
            ];

            Log::error('A records index could not be created.', $context);

            if ($isConcurrent) {
                $this->discardInvalidIndex($indexName, $context);
            }

            throw IndexCreationFailedException::for($indexName, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function discardInvalidIndex(string $indexName, array $context): void
    {
        try {
            $this->dropIndex($indexName);
        } catch (QueryException $exception) {
            Log::error('The invalid remains of a failed records index could not be dropped.', [
                ...$context,
                'drop_sql_state' => $exception->getCode(),
            ]);
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
