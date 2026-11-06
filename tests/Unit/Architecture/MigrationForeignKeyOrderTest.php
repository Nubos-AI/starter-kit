<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Support\ModuleRoots;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->queryingMigrations = array_values(array_filter(
        [
            'admin/database/migrations/1001_01_01_000003_create_support_message_attachments_table.php',
            'pipelines/database/migrations/1002_01_01_000000_add_pipeline_attributes_to_custom_records.php',
            'pipelines/database/migrations/1002_01_01_000006_add_pipeline_context_to_approvals.php',
            'pipelines/database/migrations/1002_01_01_000007_add_kanban_axis_to_field_definitions.php',
            'sandbox/database/migrations/1005_01_01_000000_extend_tenant_context_for_sandbox.php',
        ],
        static fn (string $migration): bool => array_key_exists(explode('/', $migration)[0], ModuleRoots::all()),
    ));

    /** @var callable(string): list<string> */
    $this->foreignKeysBeforeTheirOwnPrimaryKey = function (string $path): array {
        $keyed = [];
        $offenders = [];

        foreach (SchemaShape::ofMigration($path)->statements as $statement) {
            if (preg_match('/^create table "(\w+)" \(.*primary key/s', $statement, $match) === 1) {
                $keyed[$match[1]] = true;
            }

            if (preg_match('/^alter table "(\w+)" add primary key/', $statement, $match) === 1) {
                $keyed[$match[1]] = true;
            }

            if (preg_match('/^alter table "(\w+)" add constraint "(\w+)" foreign key .* references "(\w+)"/', $statement, $match) === 1
                && $match[1] === $match[3]
                && !isset($keyed[$match[3]])) {
                $offenders[] = "{$path} → {$match[2]}";
            }
        }

        return $offenders;
    };
});

it('adds a self-referencing foreign key only after the primary key it points at exists', function (): void {
    $migrations = [
        ...glob(base_path('database/migrations/*.php')) ?: [],
        ...ModuleRoots::files('database/migrations/*.php'),
    ];

    $offenders = [];
    $querying = [];

    foreach ($migrations as $migration) {
        $path = str_replace(base_path().'/', '', $migration);

        try {
            $offenders = [...$offenders, ...($this->foreignKeysBeforeTheirOwnPrimaryKey)($path)];
        } catch (QueryException|PDOException) {
            $querying[] = ModuleRoots::relativePath($migration);
        }
    }

    expect($migrations)->not->toBeEmpty()
        ->and($querying)->toBe($this->queryingMigrations)
        ->and($offenders)->toBe([]);
});
