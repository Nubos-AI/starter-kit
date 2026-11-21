<?php

declare(strict_types=1);

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Architecture\ModelSchemaExceptions;
use Tests\Support\ModuleRoots;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->timestampColumns = ['id', 'deleted_at', 'created_at', 'updated_at'];
    $this->castedTypePattern = '/^(boolean|jsonb?|date|timestamp)\b/i';

    /** @var callable(string): list<string> */
    $this->topLevelParts = static function (string $body): array {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';

        foreach (str_split($body) as $character) {
            if ($character === "'") {
                $quoted = !$quoted;
            }

            if (!$quoted && $character === '(') {
                $depth++;
            }

            if (!$quoted && $character === ')') {
                $depth--;
            }

            if (!$quoted && $depth === 0 && $character === ',') {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return $parts;
    };

    /** @var callable(array<string, array<string, string>>, string): array<string, array<string, string>> */
    $this->applyStatement = function (array $tables, string $statement): array {
        if (preg_match('/^create table "(\w+)" \((.*)\)\s*$/s', $statement, $match) === 1) {
            $tables[$match[1]] = [];

            foreach (($this->topLevelParts)($match[2]) as $part) {
                if (preg_match('/^"(\w+)" (.*)$/s', $part, $column) === 1) {
                    $tables[$match[1]][$column[1]] = $column[2];
                }
            }

            return $tables;
        }

        if (preg_match('/^drop table (?:if exists )?"(\w+)"/', $statement, $match) === 1) {
            unset($tables[$match[1]]);

            return $tables;
        }

        if (preg_match('/^alter table "(\w+)" rename to "(\w+)"/', $statement, $match) === 1) {
            $tables[$match[2]] = $tables[$match[1]] ?? [];
            unset($tables[$match[1]]);

            return $tables;
        }

        if (preg_match('/^alter table "(\w+)" rename column "(\w+)" to "(\w+)"/', $statement, $match) === 1) {
            $renamed = [];

            foreach ($tables[$match[1]] ?? [] as $name => $definition) {
                $renamed[$name === $match[2] ? $match[3] : $name] = $definition;
            }

            $tables[$match[1]] = $renamed;

            return $tables;
        }

        if (preg_match('/^alter table "(\w+)" (.*)$/s', $statement, $match) !== 1) {
            return $tables;
        }

        foreach (($this->topLevelParts)($match[2]) as $part) {
            if (preg_match('/^add column "(\w+)" (.*)$/s', $part, $column) === 1) {
                $tables[$match[1]][$column[1]] = $column[2];
            }

            if (preg_match('/^drop column "(\w+)"/', $part, $column) === 1) {
                unset($tables[$match[1]][$column[1]]);
            }

            if (preg_match('/^alter column "(\w+)" type (.*)$/s', $part, $column) === 1 && isset($tables[$match[1]][$column[1]])) {
                $tables[$match[1]][$column[1]] = $column[2];
            }
        }

        return $tables;
    };

    /** @var callable(string): string */
    $this->relative = static fn (string $path): string => str_replace(base_path().'/', '', $path);

    /** @var array<string, array{migrations: list<string>, namespace: string, models: string}> $origins */
    $origins = ['core' => [
        'migrations' => glob(base_path('database/migrations/*.php')) ?: [],
        'namespace' => 'App\\',
        'models' => app_path('Models'),
    ]];

    foreach (ModuleRoots::all() as $module => $root) {
        $composer = json_decode((string) file_get_contents("{$root}/composer.json"), true, 512, JSON_THROW_ON_ERROR);
        $namespace = (string) array_search('src/', $composer['autoload']['psr-4'], true);

        $origins[$module] = [
            'migrations' => glob("{$root}/database/migrations/*.php") ?: [],
            'namespace' => $namespace,
            'models' => "{$root}/src/Models",
        ];
    }

    $this->origins = $origins;

    /** @var callable(string): array{tables: array<string, array<string, string>>, underived: array<string, string>} */
    $this->schemaOf = function (string $origin): array {
        $tables = [];
        $underived = [];

        foreach ($this->origins[$origin]['migrations'] as $migration) {
            try {
                $statements = SchemaShape::ofMigration(($this->relative)($migration))->statements;
            } catch (QueryException|PDOException) {
                preg_match_all("/Schema::(?:create|table)\\(\\s*'(\\w+)'/", (string) file_get_contents($migration), $touched);

                foreach ($touched[1] as $table) {
                    $underived[$table] = ($this->relative)($migration);
                }

                continue;
            }

            foreach ($statements as $statement) {
                $tables = ($this->applyStatement)($tables, $statement);
            }
        }

        return ['tables' => $tables, 'underived' => $underived];
    };

    /** @var callable(string): list<class-string<Model>> */
    $this->modelsOf = function (string $origin): array {
        $directory = $this->origins[$origin]['models'];

        if (!is_dir($directory)) {
            return [];
        }

        $models = [];

        foreach (File::allFiles($directory) as $file) {
            $class = $this->origins[$origin]['namespace'].'Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (!class_exists($class) || !is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    };

    /** @var callable(Model, class-string): ?ReflectionAttribute<object> */
    $this->classAttribute = static function (Model $model, string $attribute): ?ReflectionAttribute {
        for ($reflection = new ReflectionClass($model); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $found = $reflection->getAttributes($attribute);

            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    };

    /** @var callable(callable(class-string<Model>, Model, array<string, string>): list<string>): list<string> */
    $this->offendersAcrossModels = function (callable $inspect): array {
        $offenders = [];

        foreach (array_keys($this->origins) as $origin) {
            $schema = ($this->schemaOf)($origin);

            foreach (($this->modelsOf)($origin) as $class) {
                /** @var Model $model */
                $model = new $class;
                $table = $model->getTable();

                if (isset($schema['underived'][$table])) {
                    $offenders[] = "{$class} [{$table}]: schema not derivable from {$schema['underived'][$table]}";

                    continue;
                }

                if (!isset($schema['tables'][$table])) {
                    $offenders[] = "{$class} [{$table}]: no {$origin} migration creates the table";

                    continue;
                }

                $offenders = [...$offenders, ...array_map(
                    static fn (string $finding): string => "{$class} [{$table}]: {$finding}",
                    $inspect($class, $model, $schema['tables'][$table]),
                )];
            }
        }

        return $offenders;
    };
});

it('declares every table column except the key, deleted_at and the timestamps Eloquent maintains in #[Fillable] in migration order', function (): void {
    $offenders = ($this->offendersAcrossModels)(function (string $class, Model $model, array $columns): array {
        $managed = $model->usesTimestamps() ? $this->timestampColumns : ['id', 'deleted_at'];
        $unfillable = ModelSchemaExceptions::$unfillableColumns[$class] ?? [];
        $expected = array_values(array_diff(array_keys($columns), $managed, array_keys($unfillable)));
        $attribute = ($this->classAttribute)($model, Fillable::class);
        $fillable = $attribute === null ? [] : array_values((array) ($attribute->getArguments()[0] ?? []));
        $stale = array_values(array_diff(array_keys($unfillable), array_diff(array_keys($columns), $fillable)));

        if ($stale !== []) {
            return array_map(static fn (string $column): string => "exception for column {$column} is stale", $stale);
        }

        if ($fillable === $expected) {
            return [];
        }

        $missing = array_values(array_diff($expected, $fillable));
        $unknown = array_values(array_diff($fillable, $expected));

        if ($missing === [] && $unknown === []) {
            return ['#[Fillable] order differs, expected '.implode(', ', $expected)];
        }

        return array_merge(
            array_map(static fn (string $column): string => "column {$column} missing from #[Fillable]", $missing),
            array_map(static fn (string $column): string => "#[Fillable] lists {$column}, which the table does not have", $unknown),
        );
    });

    expect($offenders)->toBe([]);
});

it('gives every model a ULID primary key through HasUlids', function (): void {
    $offenders = ($this->offendersAcrossModels)(static fn (string $class): array => in_array(HasUlids::class, class_uses_recursive($class), true)
        ? []
        : ['HasUlids is missing']);

    expect($offenders)->toBe([]);
});

it('uses SoftDeletes exactly when the table has a deleted_at column', function (): void {
    $offenders = ($this->offendersAcrossModels)(static function (string $class, Model $model, array $columns): array {
        $hasColumn = array_key_exists('deleted_at', $columns);
        $usesTrait = in_array(SoftDeletes::class, class_uses_recursive($class), true);

        return match (true) {
            $hasColumn && !$usesTrait => ['column deleted_at exists but SoftDeletes is missing'],
            !$hasColumn && $usesTrait => ['SoftDeletes is used but column deleted_at does not exist'],
            default => [],
        };
    });

    expect($offenders)->toBe([]);
});

it('scopes a model by TenantScope exactly when its table has a tenant_id column, apart from the named exceptions', function (): void {
    $offenders = ($this->offendersAcrossModels)(function (string $class, Model $model, array $columns): array {
        $attribute = ($this->classAttribute)($model, ScopedBy::class);
        $isScoped = $attribute !== null && in_array(TenantScope::class, (array) ($attribute->getArguments()[0] ?? []), true);
        $hasColumn = array_key_exists('tenant_id', $columns);

        if (array_key_exists($class, ModelSchemaExceptions::$unscopedTenantModels)) {
            return $hasColumn && !$isScoped ? [] : ['exception for the missing TenantScope is stale'];
        }

        return match (true) {
            $hasColumn && !$isScoped => ['column tenant_id exists but #[ScopedBy([TenantScope::class])] is missing'],
            !$hasColumn && $isScoped => ['#[ScopedBy([TenantScope::class])] is declared but column tenant_id does not exist'],
            default => [],
        };
    });

    expect($offenders)->toBe([]);
});

it('casts every boolean, json, date and timestamp column or converts it through an attribute accessor', function (): void {
    $offenders = ($this->offendersAcrossModels)(function (string $class, Model $model, array $columns): array {
        $casts = $model->getCasts();
        $findings = [];

        foreach ($columns as $column => $definition) {
            if (in_array($column, $this->timestampColumns, true) || preg_match($this->castedTypePattern, $definition) !== 1) {
                continue;
            }

            if (!array_key_exists($column, $casts) && !$model->hasAttributeGetMutator($column)) {
                $findings[] = "column {$column} ({$definition}) has no cast";
            }
        }

        return $findings;
    });

    expect($offenders)->toBe([]);
});

it('drops exactly the tables it creates in the down method of every create table migration', function (): void {
    $offenders = [];

    foreach ($this->origins as $origin) {
        foreach ($origin['migrations'] as $migration) {
            if (preg_match('/_create_\w+_table\.php$/', $migration) !== 1) {
                continue;
            }

            $path = ($this->relative)($migration);

            try {
                $up = SchemaShape::ofMigration($path)->statements;
                $down = SchemaShape::ofMigration($path, 'down')->statements;
            } catch (QueryException|PDOException) {
                $offenders[] = "{$path}: up or down queries the database";

                continue;
            }

            preg_match_all('/^create table "(\w+)"/m', implode("\n", $up), $created);
            preg_match_all('/^drop table (?:if exists )?"(\w+)"/m', implode("\n", $down), $dropped);

            $createdTables = array_values(array_unique($created[1]));
            $droppedTables = array_values(array_unique($dropped[1]));
            sort($createdTables);
            sort($droppedTables);

            if ($createdTables !== $droppedTables) {
                $offenders[] = "{$path}: creates [".implode(', ', $createdTables).'] but drops ['.implode(', ', $droppedTables).']';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps a column out of #[Fillable] only for a named reason', function (string $class, string $column): void {
    $attribute = ($this->classAttribute)(new $class, Fillable::class);

    expect($attribute)->not->toBeNull()
        ->and((array) ($attribute?->getArguments()[0] ?? []))->not->toContain($column);
})->with(static function (): array {
    $cases = [];

    foreach (ModelSchemaExceptions::$unfillableColumns as $class => $columns) {
        foreach ($columns as $column => $reason) {
            $cases["{$class}.{$column}: {$reason}"] = [$class, $column];
        }
    }

    return $cases;
});

it('leaves a tenant model without TenantScope only for a named reason', function (string $class): void {
    $attribute = ($this->classAttribute)(new $class, ScopedBy::class);

    expect((array) ($attribute?->getArguments()[0] ?? []))->not->toContain(TenantScope::class);
})->with(static fn (): array => collect(ModelSchemaExceptions::$unscopedTenantModels)
    ->mapWithKeys(static fn (string $reason, string $class): array => ["{$class}: {$reason}" => [$class]])
    ->all());
