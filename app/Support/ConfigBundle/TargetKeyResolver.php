<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Scopes\ObjectTypeTenantScope;
use App\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TargetKeyResolver
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rowsByTable = [];

    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $index = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $widgetKeys = null;

    private ?string $indexedTenantId = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $tables = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $declarations = null;

    /**
     * @var list<string>|null
     */
    private ?array $reservedTokens = null;

    public function idFor(ArtifactKind|string $kind, string $businessKey): ?string
    {
        $table = $kind instanceof ArtifactKind ? $this->tableFor($kind) : $kind;

        if ($table === null) {
            return null;
        }

        return $this->index()[$table][$businessKey] ?? null;
    }

    public function parentIdFor(ArtifactKind $kind, string $businessKey, string $column): ?string
    {
        $edge = $this->edgeFor($kind, $column);

        if ($edge === null) {
            return null;
        }

        $parent = ArtifactKind::tryFrom($this->textOf($edge, 'kind'));

        if ($parent === null) {
            return null;
        }

        $candidate = $this->candidateKeyFor($edge, $businessKey, $parent);

        return $candidate === null ? null : $this->idFor($parent, $candidate);
    }

    public function knownPrefixOf(ArtifactKind $parent, string $businessKey): ?string
    {
        $components = explode(':', $businessKey);

        for ($length = count($components) - 1; $length > 0; $length--) {
            $candidate = implode(':', array_slice($components, 0, $length));

            if ($this->isReserved($candidate)) {
                return null;
            }

            if ($this->idFor($parent, $candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function invalidate(ArtifactKind $kind): void
    {
        foreach ($this->staleTablesFor($kind) as $table) {
            unset($this->rowsByTable[$table]);
        }

        $this->index = null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function index(): array
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return [];
        }

        return $this->indexOf($tenantId);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function indexOf(string $tenantId): array
    {
        if ($this->indexedTenantId !== $tenantId) {
            $this->forgetReadRows();
        }

        if ($this->index !== null) {
            return $this->index;
        }

        $this->rowsByTable = $this->readRows($tenantId);
        $this->indexedTenantId = $tenantId;

        /** @var class-string<BusinessKeyResolver> $resolverClass */
        $resolverClass = config('engine.config_bundle.resolver', BusinessKeyResolver::class);
        $resolver = new $resolverClass($this->rowsByTable);
        $widgetKeys = $this->frozenWidgetKeys($resolver);
        $index = [];

        foreach ($this->rowsByTable as $table => $rows) {
            foreach ($rows as $row) {
                $id = $this->identifierOf($row['id'] ?? null);

                if ($id === null) {
                    continue;
                }

                $key = $table === 'dashboard_widgets' ? ($widgetKeys[$id] ?? null) : $resolver->keyFor($table, $id);

                if ($key === null) {
                    continue;
                }

                $index[$table] ??= [];
                $index[$table][$key] ??= $id;
            }
        }

        return $this->index = $index;
    }

    private function forgetReadRows(): void
    {
        $this->rowsByTable = [];
        $this->widgetKeys = null;
        $this->index = null;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function readRows(string $tenantId): array
    {
        $ordered = [];

        foreach ($this->entries() as $entry) {
            $table = (string) $entry['table'];

            if (!$this->isKeyBearing($entry)) {
                continue;
            }

            $ordered[$table] = $this->rowsByTable[$table] ?? $this->rowsOf($entry, $tenantId, $ordered);
        }

        return $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        $configured = config('engine.tenant_artifacts');

        return array_values(array_filter(is_array($configured) ? $configured : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isKeyBearing(array $entry): bool
    {
        if (($entry['model'] ?? null) === null) {
            return false;
        }

        if (($entry['bundle'] ?? false) === true) {
            return true;
        }

        $configured = config('engine.config_bundle.key_source_tables');
        $tables = is_array($configured) ? $configured : [];

        return in_array((string) $entry['table'], array_map(
            static fn (mixed $table): string => (string) $table,
            $tables,
        ), true);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     * @return list<array<string, mixed>>
     */
    protected function rowsOf(array $entry, string $tenantId, array $rowsByTable): array
    {
        $filter = $this->filterFor($entry, $tenantId, $rowsByTable);

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['model'];

        $builder = $modelClass::query()->withoutGlobalScopes([TenantScope::class, ObjectTypeTenantScope::class]);
        $filter($builder);

        /** @var list<string> $secretColumns */
        $secretColumns = $entry['secret_columns'] ?? [];

        return array_values(
            $builder->get()
                ->map(fn (Model $model): array => $this->attributesOf($model, $secretColumns))
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     * @return Closure(EloquentBuilder<Model>|QueryBuilder): mixed
     */
    private function filterFor(array $entry, string $tenantId, array $rowsByTable): Closure
    {
        if ((string) $entry['scope'] !== 'via') {
            return static fn (EloquentBuilder|QueryBuilder $query): mixed => $query->where('tenant_id', $tenantId);
        }

        $viaColumn = (string) $entry['via_column'];
        /** @var array<string, string> $remap */
        $remap = $entry['remap'];
        $carrierIds = array_column($rowsByTable[$remap[$viaColumn]] ?? [], 'id');

        return static fn (EloquentBuilder|QueryBuilder $query): mixed => $query->whereIn($viaColumn, $carrierIds);
    }

    /**
     * @param  list<string>  $secretColumns
     * @return array<string, mixed>
     */
    private function attributesOf(Model $model, array $secretColumns): array
    {
        $attributes = [];

        foreach (array_keys($model->getAttributes()) as $column) {
            if (in_array($column, $secretColumns, true)) {
                continue;
            }

            $attributes[$column] = $model->getAttribute($column);
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function frozenWidgetKeys(BusinessKeyResolver $resolver): array
    {
        if ($this->widgetKeys !== null) {
            return $this->widgetKeys;
        }

        $keys = [];

        foreach ($this->rowsByTable['dashboard_widgets'] ?? [] as $row) {
            $id = $this->identifierOf($row['id'] ?? null);
            $key = $id === null ? null : $resolver->keyFor('dashboard_widgets', $id);

            if ($id === null || $key === null) {
                continue;
            }

            $keys[$id] = $key;
        }

        return $this->widgetKeys = $keys;
    }

    /**
     * @return list<string>
     */
    private function staleTablesFor(ArtifactKind $kind): array
    {
        $tables = [];
        $own = $this->tableFor($kind);

        if ($own !== null) {
            $tables[] = $own;
        }

        foreach (array_keys($this->declarations()) as $name) {
            $dependent = ArtifactKind::tryFrom($name);

            if ($dependent === null || !$this->dependsOn($dependent, $kind)) {
                continue;
            }

            $table = $this->tableFor($dependent);

            if ($table === null) {
                continue;
            }

            $tables[] = $table;
        }

        return array_values(array_unique($tables));
    }

    private function dependsOn(ArtifactKind $dependent, ArtifactKind $kind): bool
    {
        foreach ($this->edgesOf($dependent) as $edge) {
            if ($this->textOf($edge, 'kind') === $kind->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function edgeFor(ArtifactKind $kind, string $column): ?array
    {
        foreach ($this->edgesOf($kind) as $edge) {
            if ($this->textOf($edge, 'column') === $column) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function edgesOf(ArtifactKind $kind): array
    {
        $declaration = $this->declarations()[$kind->value] ?? [];
        $edges = [];

        foreach (['requires', 'optional'] as $category) {
            $configured = $declaration[$category] ?? null;

            foreach (is_array($configured) ? $configured : [] as $edge) {
                if (is_array($edge)) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function candidateKeyFor(array $edge, string $businessKey, ArtifactKind $parent): ?string
    {
        if ($this->textOf($edge, 'source') === 'payload') {
            return null;
        }

        $components = explode(':', $businessKey);
        $derivation = $this->textOf($edge, 'derivation');

        if ($derivation === 'recompose') {
            $qualifier = $edge['qualifier'] ?? null;
            $qualifier = is_array($qualifier) ? $qualifier : [];
            $component = array_slice($components, $this->intOf($edge, 'component'), 1);
            $prefix = implode(':', array_slice($components, $this->intOf($qualifier, 'offset'), $this->lengthOf($qualifier)));

            if ($component === [] || $prefix === '' || $this->isReserved($component[0])) {
                return null;
            }

            return $prefix.':'.$component[0];
        }

        if ($derivation === 'prefix') {
            $known = $this->knownPrefixOf($parent, $businessKey);

            if ($known !== null) {
                return $known;
            }

            if (count($components) > 1 && $this->isReserved($components[0])) {
                return null;
            }
        }

        $fallback = implode(':', array_slice($components, $this->intOf($edge, 'offset'), $this->lengthOf($edge)));

        if ($fallback === '' || $this->isReserved($fallback)) {
            return null;
        }

        return $fallback;
    }

    private function tableFor(ArtifactKind $kind): ?string
    {
        if ($this->tables === null) {
            $tables = [];

            foreach ($this->entries() as $entry) {
                $name = $entry['kind'] ?? null;

                if (is_string($name)) {
                    $tables[$name] = (string) $entry['table'];
                }
            }

            $this->tables = $tables;
        }

        return $this->tables[$kind->value] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function declarations(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }

        $configured = config('engine.artifact_dependencies');
        $declarations = [];

        foreach (is_array($configured) ? $configured : [] as $name => $entry) {
            if (is_string($name) && is_array($entry)) {
                $declarations[$name] = $entry;
            }
        }

        return $this->declarations = $declarations;
    }

    private function isReserved(string $value): bool
    {
        if ($this->reservedTokens === null) {
            $configured = config('engine.config_bundle.reserved_tokens');
            $tokens = [];

            foreach (is_array($configured) ? $configured : [] as $token) {
                if (is_string($token)) {
                    $tokens[] = $token;
                }
            }

            $this->reservedTokens = $tokens;
        }

        return in_array($value, $this->reservedTokens, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function textOf(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intOf(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lengthOf(array $data): ?int
    {
        $value = $data['length'] ?? null;

        return is_int($value) ? $value : null;
    }

    private function identifierOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
