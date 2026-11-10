<?php

declare(strict_types=1);

use App\Support\Modules\ModuleRequirementWriter;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['modules.installable' => [
        'nubos/pipelines' => 'Pipelines und Kanban',
        'nubos/documents' => 'Dokumentvorlagen',
    ]]);

    $this->choices = [
        'nubos/pipelines' => 'nubos/pipelines – Pipelines und Kanban',
        'nubos/documents' => 'nubos/documents – Dokumentvorlagen',
    ];
    $this->writer = Mockery::mock(ModuleRequirementWriter::class);
    app()->instance(ModuleRequirementWriter::class, $this->writer);
});

afterEach(function (): void {
    app()->forgetInstance(ModuleRequirementWriter::class);
    Mockery::close();
});

it('offers every free package with its description and adds the chosen ones to composer.json', function (): void {
    $this->writer->shouldReceive('add')->once()->with(['nubos/documents']);

    $this->artisan('modules:install')
        ->expectsChoice('Welche kostenlosen Nubos-Pakete sollen installiert werden?', ['nubos/documents'], $this->choices)
        ->assertSuccessful();
});

it('leaves composer.json untouched when no package is chosen', function (): void {
    $this->writer->shouldNotReceive('add');

    $this->artisan('modules:install')
        ->expectsChoice('Welche kostenlosen Nubos-Pakete sollen installiert werden?', [], $this->choices)
        ->assertSuccessful();
});

it('asks for the free packages from the composer scripts of create-project, independent of the laravel installer version', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['scripts']['post-create-project-cmd'])->toBe([
        '@php artisan key:generate --ansi',
        '@php artisan modules:install',
        '@composer update "nubos/*" --no-scripts',
        '@php artisan package:discover --ansi',
        'vendor/bin/sail up -d --wait laravel.test pgsql',
        'vendor/bin/sail artisan migrate --ansi',
        'vendor/bin/sail artisan modules:sync --ansi',
    ])
        ->and($composer['extra']['laravel'])->not->toHaveKey('installer');
});

it('copies an env template whose database settings match the sail services', function (): void {
    $template = (string) file_get_contents(base_path('.env.example'));

    expect($template)
        ->toContain("\nDB_CONNECTION=pgsql\n")
        ->toContain("\nDB_HOST=pgsql\n")
        ->toContain("\nDB_PORT=5432\n")
        ->toContain("\nDB_DATABASE=nubos\n")
        ->toContain("\nDB_USERNAME=sail\n")
        ->toContain("\nDB_PASSWORD=password\n")
        ->toContain("\nREDIS_HOST=redis\n");
});
