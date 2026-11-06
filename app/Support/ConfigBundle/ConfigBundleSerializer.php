<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\BundleManifest;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\AmbiguousArtifactKeyException;
use App\Models\Tenant;
use App\Scopes\ObjectTypeTenantScope;
use App\Scopes\TenantScope;
use App\Support\ConfigBundle\SchemaVersion\BundleSchemaMigratorRegistry;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConfigBundleSerializer
{
    private string $manifestFile;

    private int $maxFileNameComponent = 48;

    private int $truncatedFileNameComponent = 39;

    private int $fileNameHashLength = 8;

    /**
     * @var list<string>
     */
    private array $keySourceTables;

    public function __construct(
        private readonly YamlCodec $codec,
        private readonly BundleSchemaMigratorRegistry $migrators,
    ) {
        $this->manifestFile = (string) config('engine.config_bundle.manifest_file');

        $tables = config('engine.config_bundle.key_source_tables');
        $this->keySourceTables = array_values(array_map(
            static fn (mixed $table): string => (string) $table,
            is_array($tables) ? $tables : [],
        ));
    }

    public function serialize(string $tenantId): ConfigBundle
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $entries = $this->entries();
        $rowsByTable = [];

        foreach ($entries as $entry) {
            $table = (string) $entry['table'];

            if (!$this->isBundled($entry) && !in_array($table, $this->keySourceTables, true)) {
                continue;
            }

            $rowsByTable[$table] = $this->readRows($entry, $tenantId, $rowsByTable);
        }

        /** @var class-string<BusinessKeyResolver> $resolverClass */
        $resolverClass = config('engine.config_bundle.resolver', BusinessKeyResolver::class);
        $resolver = new $resolverClass($rowsByTable);
        $rewriter = new PayloadRewriter($resolver);

        $artifacts = [];
        $warnings = [];
        $fileNames = [];

        foreach ($entries as $entry) {
            if (!$this->isBundled($entry)) {
                continue;
            }

            $kind = ArtifactKind::from((string) $entry['kind']);

            foreach ($this->keyedRows($entry, $kind, $rowsByTable, $resolver, $warnings) as [$key, $row]) {
                $this->claimFileName($kind, $key, $fileNames);

                $artifacts[] = new BundleArtifact($kind, $key, $this->codec->normalize(
                    $this->payloadOf($entry, $kind, $key, $row, $resolver),
                    $rewriter->translatorFor($kind->identifierFor($key)),
                ));
            }
        }

        return new ConfigBundle(
            $this->manifest($tenant, $artifacts, [...$warnings, ...$rewriter->warnings()]),
            $artifacts,
        );
    }

    public function writeTo(ConfigBundle $bundle, string $directory): void
    {
        File::ensureDirectoryExists($directory);
        File::put($directory.'/'.$this->manifestFile, $this->codec->dump($bundle->manifest->toArray()));

        foreach ($bundle->artifacts as $artifact) {
            File::ensureDirectoryExists($directory.'/'.$artifact->kind->value);
            File::put(
                $directory.'/'.$this->relativePathFor($artifact->kind, $artifact->key),
                $this->codec->dump($artifact->payload),
            );
        }
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
    private function isBundled(array $entry): bool
    {
        return ($entry['bundle'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     * @return list<array<string, mixed>>
     */
    private function readRows(array $entry, string $tenantId, array $rowsByTable): array
    {
        return match ((string) $entry['scope']) {
            'via' => $this->rows($entry, $this->carrierFilter($entry, $rowsByTable)),
            'direct', 'tenant_owned_only' => $this->rows(
                $entry,
                static fn (EloquentBuilder|QueryBuilder $query): mixed => $query->where('tenant_id', $tenantId),
            ),
            default => throw new InvalidArgumentException(__('i18n.backend.support.config_bundle.config_bundle_serializer.the_table_specifies_the_unknown_scope', ['value1' => $entry['table'], 'value2' => $entry['scope']])),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     * @return Closure(EloquentBuilder<Model>|QueryBuilder): mixed
     */
    private function carrierFilter(array $entry, array $rowsByTable): Closure
    {
        $viaColumn = (string) $entry['via_column'];
        /** @var array<string, string> $remap */
        $remap = $entry['remap'];
        $carrierIds = array_column($rowsByTable[$remap[$viaColumn]] ?? [], 'id');

        return static fn (EloquentBuilder|QueryBuilder $query): mixed => $query->whereIn($viaColumn, $carrierIds);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Closure(EloquentBuilder<Model>|QueryBuilder): mixed  $filter
     * @return list<array<string, mixed>>
     */
    private function rows(array $entry, Closure $filter): array
    {
        $modelClass = $entry['model'] ?? null;

        if ($modelClass === null) {
            $query = DB::table((string) $entry['table']);
            $filter($query);

            return array_values($query->get()->map(static fn (object $row): array => (array) $row)->all());
        }

        /** @var class-string<Model> $modelClass */
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
     * @param  array<string, mixed>  $entry
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     * @param  list<string>  $warnings
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function keyedRows(array $entry, ArtifactKind $kind, array $rowsByTable, BusinessKeyResolver $resolver, array &$warnings): array
    {
        $table = (string) $entry['table'];
        $keyed = [];

        foreach ($rowsByTable[$table] ?? [] as $row) {
            $key = $table === 'role_permission'
                ? $resolver->rolePermissionKey($this->identifierOf($row['role_id'] ?? null), $this->identifierOf($row['permission_id'] ?? null))
                : $resolver->keyFor($table, $this->identifierOf($row['id'] ?? null));

            if ($key === null) {
                if (!$this->isDeliberatelySkipped($table, $row, $resolver)) {
                    $warnings[] = "{$kind->value}: ".__('i18n.backend.support.config_bundle.config_bundle_serializer.at_least_one_row_of_this_artifact_type_was');
                }

                continue;
            }

            $keyed[] = [$key, $row];
        }

        usort($keyed, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function payloadOf(array $entry, ArtifactKind $kind, string $key, array $row, BusinessKeyResolver $resolver): array
    {
        $configured = config("engine.config_bundle.fields.{$kind->value}");

        if (!is_array($configured) || $configured === []) {
            throw new InvalidArgumentException(__('i18n.backend.support.config_bundle.config_bundle_serializer.the_artifact_kind_has_no_field_list_in_engine', ['value1' => $kind->value]));
        }

        /** @var list<string> $placeholderColumns */
        $placeholderColumns = $entry['placeholder_columns'] ?? [];
        $placeholder = (string) config('engine.config_bundle.placeholder');

        $payload = [];

        foreach ($configured as $field) {
            $field = (string) $field;

            if ($field === 'key') {
                $payload['key'] = $key;

                continue;
            }

            $virtual = config("engine.config_bundle.virtual_fields.{$entry['table']}.{$field}");
            if (is_array($virtual)) {
                $payload[$field] = $resolver->relatedKeys($virtual['pivot'], $virtual['owner'], $virtual['table'], $virtual['related'], $this->identifierOf($row['id'] ?? null));

                continue;
            }

            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException(__('i18n.backend.support.config_bundle.config_bundle_serializer.the_artifact_kind_specifies_field_which_does_not_exist', ['value1' => $kind->value, 'value2' => $field, 'value3' => $entry['table']]));
            }

            $payload[$field] = in_array($field, $placeholderColumns, true) ? $placeholder : $row[$field];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isDeliberatelySkipped(string $table, array $row, BusinessKeyResolver $resolver): bool
    {
        if ($table === 'dashboards') {
            return ($row['is_tenant_wide'] ?? false) !== true;
        }

        if ($table === 'dashboard_widgets') {
            return $resolver->keyFor('dashboards', $this->identifierOf($row['dashboard_id'] ?? null)) === null;
        }

        return false;
    }

    /**
     * @param  list<BundleArtifact>  $artifacts
     * @param  list<string>  $warnings
     */
    private function manifest(Tenant $tenant, array $artifacts, array $warnings): BundleManifest
    {
        $counts = [];

        foreach (ArtifactKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        foreach ($artifacts as $artifact) {
            $counts[$artifact->kind->value] = ($counts[$artifact->kind->value] ?? 0) + 1;
        }

        $unique = array_values(array_unique($warnings));
        sort($unique);

        return new BundleManifest(
            schemaVersion: $this->migrators->currentVersion(),
            sourceLabel: $tenant->name,
            artifactCounts: $counts,
            warnings: $unique,
        );
    }

    /**
     * @param  array<string, string>  $fileNames
     */
    private function claimFileName(ArtifactKind $kind, string $key, array &$fileNames): void
    {
        $relative = $this->relativePathFor($kind, $key);

        if (isset($fileNames[$relative])) {
            throw AmbiguousArtifactKeyException::fileNameCollision($kind, $fileNames[$relative], $key, $relative);
        }

        $fileNames[$relative] = $key;
    }

    private function relativePathFor(ArtifactKind $kind, string $key): string
    {
        $components = array_map(
            fn (string $component): string => $this->fileNameComponent($component),
            explode(':', $key),
        );

        return $kind->value.'/'.implode('__', $components).'.yaml';
    }

    private function fileNameComponent(string $component): string
    {
        $slug = Str::slug($component);
        $digest = substr(hash('sha256', $component), 0, $this->fileNameHashLength);

        if ($slug === '') {
            return $digest;
        }

        if (strlen($slug) > $this->maxFileNameComponent) {
            return substr($slug, 0, $this->truncatedFileNameComponent).'-'.$digest;
        }

        return $slug;
    }

    private function identifierOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
