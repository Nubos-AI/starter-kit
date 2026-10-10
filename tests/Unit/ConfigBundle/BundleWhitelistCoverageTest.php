<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $configured = config('engine.tenant_artifacts');

    $this->entries = array_values(array_filter(is_array($configured) ? $configured : [], 'is_array'));

    $blockTables = [];

    foreach ($this->entries as $entry) {
        $table = $entry['table'] ?? null;

        if (is_string($table)) {
            $blockTables[] = $table;
        }
    }

    $blockColumns = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
        $schema = SchemaShape::ofMigration('database/migrations/'.basename($path));

        foreach ($blockTables as $table) {
            if ($schema->has('create table "'.$table.'" (')) {
                $blockColumns[$table] = $schema->columnsOf($table);
            }
        }
    }

    $this->blockColumns = $blockColumns;

    $this->hasColumn = static fn (string $table, string $column): bool => in_array($column, $blockColumns[$table] ?? [], true);

    $this->secretNamePatterns = [
        'secret',
        'password',
        'token',
        'credential',
        'auth_',
        'salt',
        'passphrase',
        'private_key',
        'api_key',
    ];

    $this->targetNamePatterns = ['url', 'endpoint', 'callback'];

    $this->columnsLike = function (array $patterns) use ($blockColumns): array {
        $matches = [];

        foreach ($blockColumns as $table => $columns) {
            foreach ($columns as $column) {
                foreach ($patterns as $pattern) {
                    if (str_contains($column, $pattern)) {
                        $matches[$table][] = $column;

                        break;
                    }
                }
            }
        }

        return $matches;
    };

    $this->entryFor = function (string $table): array {
        foreach ($this->entries as $entry) {
            if (($entry['table'] ?? null) === $table) {
                return $entry;
            }
        }

        return [];
    };

    $this->tablesFlaggedWith = function (string $flag): array {
        $tables = [];

        foreach ($this->entries as $entry) {
            $table = $entry['table'] ?? null;

            if (is_string($table) && ($entry[$flag] ?? null) === true) {
                $tables[] = $table;
            }
        }

        return $tables;
    };

    $this->tablesCarryingKey = function (string $key): array {
        $tables = [];

        foreach ($this->entries as $entry) {
            $table = $entry['table'] ?? null;

            if (is_string($table) && array_key_exists($key, $entry)) {
                $tables[] = $table;
            }
        }

        return $tables;
    };

    /** @var callable(string, string ...):list<string> */
    $whenInstalled = static fn (string $package, string ...$tables): array => InstalledVersions::isInstalled($package)
        ? $tables
        : [];

    $this->artifactsNamedByTheBundleDecision = [
        'object_types',
        'field_groups',
        'field_definitions',
        'field_dependencies',
        'relationship_types',
        'merge_rules',
        'reminder_types',
        'roles',
        'role_permission',
        'field_permissions',
        'team_record_access_rules',
        'notification_rules',
        'notification_type_defaults',
        'webhook_subscriptions',
        'reports',
        'dashboards',
        'dashboard_widgets',
        'goals',
        'segments',
        'export_field_presets',
        'import_mapping_presets',
        'aging_rules',
        'skills',
        ...$whenInstalled('nubos/pipelines', 'pipelines', 'pipeline_stages', 'stage_transitions', 'transition_gates'),
        ...$whenInstalled('nubos/automations', 'automations', 'automation_templates'),
        ...$whenInstalled('nubos/documents', 'document_templates'),
    ];
});

it('bundles exactly the artifact kinds the config bundle decision names', function (): void {
    expect($this->entries)->not->toBeEmpty()
        ->and(($this->tablesFlaggedWith)('bundle'))->toEqualCanonicalizing($this->artifactsNamedByTheBundleDecision);
});

it('keeps user to role assignments out of the bundle but inside the sandbox clone', function (): void {
    $entry = ($this->entryFor)('role_assignments');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeFalse()
        ->and($entry['clone'] ?? null)->toBeTrue();
});

it('keeps permission overrides out of the bundle because they hang on users and teams', function (): void {
    $entry = ($this->entryFor)('permission_overrides');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeFalse()
        ->and($entry['clone'] ?? null)->toBeTrue();
});

it('keeps goal periods out of the bundle because they carry calculated progress, not configuration', function (): void {
    $entry = ($this->entryFor)('goal_periods');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeFalse()
        ->and($entry['clone'] ?? null)->toBeTrue();
});

it('keeps the permission catalog out of the bundle because it is regenerated, not authored', function (): void {
    $entry = ($this->entryFor)('permissions');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeFalse()
        ->and($entry['clone'] ?? null)->toBeTrue();
});

it('keeps users out of the bundle', function (): void {
    $entry = ($this->entryFor)('users');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeFalse();
});

it('bundles the dashboard widgets together with their dashboard', function (): void {
    $entry = ($this->entryFor)('dashboard_widgets');

    expect($entry)->not->toBe([])
        ->and($entry['bundle'] ?? null)->toBeTrue()
        ->and($entry['clone'] ?? null)->toBeTrue();
});

it('clones every artifact kind it bundles', function (): void {
    $bundled = ($this->tablesFlaggedWith)('bundle');
    $cloned = ($this->tablesFlaggedWith)('clone');

    expect($bundled)->not->toBeEmpty()
        ->and(array_values(array_diff($bundled, $cloned)))->toBe([]);
});

it('strips secret columns everywhere the schema stores a secret and nowhere else', function (): void {
    $secretLike = ($this->columnsLike)($this->secretNamePatterns);

    expect($this->entries)->not->toBeEmpty()
        ->and(array_keys($secretLike))->toContain('users')
        ->and(array_keys($secretLike))->toContain('webhook_subscriptions')
        ->and(($this->tablesCarryingKey)('secret_columns'))->toEqualCanonicalizing(array_keys($secretLike));

    $offenders = [];

    foreach ($this->entries as $entry) {
        $table = $entry['table'] ?? null;
        $configured = $entry['secret_columns'] ?? null;

        if (!is_string($table) || !is_array($configured)) {
            continue;
        }

        if ($configured === []) {
            $offenders[] = "{$table}: secret_columns is empty";
        }

        foreach ($configured as $column) {
            if (!is_string($column) || !($this->hasColumn)($table, $column)) {
                $offenders[] = "{$table}: secret column "
                    .(is_string($column) ? $column : '?').' does not exist';
            }
        }

        foreach ($secretLike[$table] ?? [] as $column) {
            if (!in_array($column, $configured, true)) {
                $offenders[] = "{$table}: the schema names {$column} like a secret but it is not stripped";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('replaces the webhook target with a placeholder and leaves every other artifact untouched', function (): void {
    expect($this->entries)->not->toBeEmpty()
        ->and(($this->tablesCarryingKey)('placeholder_columns'))->toEqualCanonicalizing(['webhook_subscriptions']);

    $entry = ($this->entryFor)('webhook_subscriptions');
    $configured = $entry['placeholder_columns'] ?? null;
    $columns = is_array($configured) ? $configured : [];

    $offenders = [];

    foreach ($columns as $column) {
        if (!is_string($column) || !($this->hasColumn)('webhook_subscriptions', $column)) {
            $offenders[] = 'webhook_subscriptions: placeholder column '
                .(is_string($column) ? $column : '?').' does not exist';
        }
    }

    expect($offenders)->toBe([])
        ->and($columns)->not->toBeEmpty();
});

it('carries neither secret nor placeholder columns on notification type defaults because the schema stores neither', function (): void {
    $entry = ($this->entryFor)('notification_type_defaults');
    $secretLike = ($this->columnsLike)($this->secretNamePatterns);
    $targetLike = ($this->columnsLike)($this->targetNamePatterns);

    expect($entry)->not->toBe([])
        ->and($this->blockColumns['notification_type_defaults'] ?? [])->not->toBeEmpty()
        ->and($secretLike['notification_type_defaults'] ?? [])->toBe([])
        ->and($targetLike['notification_type_defaults'] ?? [])->toBe([])
        ->and(array_key_exists('secret_columns', $entry))->toBeFalse()
        ->and(array_key_exists('placeholder_columns', $entry))->toBeFalse();
});
