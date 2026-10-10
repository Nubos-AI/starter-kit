<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->migrationFileNames = static function (): array {
        $paths = glob(database_path('migrations/*.php'));

        return collect($paths === false ? [] : $paths)
            ->map(fn (string $path): string => basename($path))
            ->values()
            ->all();
    };

    $this->incrementalMigrationNames = static function (array $names): array {
        return collect($names)
            ->filter(fn (string $name): bool => Str::isMatch('/_(add|alter|drop|remove|change|rename)_/', $name))
            ->values()
            ->all();
    };
});

it('finds migration files to inspect at all', function (): void {
    expect(($this->migrationFileNames)())->not->toBeEmpty();
});

it('carries no add alter or drop migration in the whole tree', function (): void {
    $offenders = ($this->incrementalMigrationNames)(($this->migrationFileNames)());

    expect($offenders)->toBe(
        [],
        'Vor dem ersten Release wird die ursprüngliche CREATE-Migration erweitert, statt eine Änderungsmigration nachzuschieben. Verletzende Dateien: '.implode(', ', $offenders),
    );
});

it('recognises an incremental migration by its file name', function (): void {
    $offenders = ($this->incrementalMigrationNames)([
        '0001_01_01_000014_create_custom_records_table.php',
        '2026_08_24_000001_add_stage_entered_at_to_custom_records_table.php',
        '2026_08_24_000002_alter_aging_rules_table.php',
        '2026_08_24_000003_rename_notes_table.php',
    ]);

    expect($offenders)->toBe([
        '2026_08_24_000001_add_stage_entered_at_to_custom_records_table.php',
        '2026_08_24_000002_alter_aging_rules_table.php',
        '2026_08_24_000003_rename_notes_table.php',
    ]);
});

it('keeps optional pipeline columns out of the core records migration', function (): void {
    $path = database_path('migrations/0001_01_01_000014_create_custom_records_table.php');

    expect(basename($path))->toStartWith('0001_01_01_000014_create_')
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->not->toContain('stage_entered_at', 'pipeline_id', 'stage_id');
});

it('keeps every migration file name in the create form', function (): void {
    $unexpected = collect(($this->migrationFileNames)())
        ->reject(fn (string $name): bool => Str::isMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_/', $name))
        ->values()
        ->all();

    expect($unexpected)->toBe(
        [],
        'Jede Migration legt eine Tabelle an. Abweichende Dateien: '.implode(', ', $unexpected),
    );
});
