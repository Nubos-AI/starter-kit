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

it('offers only packages whose licence is free', function (): void {
    $configuration = require base_path('config/modules.php');
    $packages = array_keys($configuration['installable']);

    foreach ($packages as $package) {
        $manifest = json_decode((string) file_get_contents(base_path("packages/{$package}/composer.json")), true);

        expect($manifest['license'])->not->toBe('LicenseRef-Nubos');
    }

    expect($packages)->not->toBeEmpty();
});

it('lets the Laravel installer ask, install the chosen packages without the module sync and discover them', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['extra']['laravel']['installer']['post-create-project'])->toBe([
        '@php artisan modules:install',
        '@composer update "nubos/*" --no-scripts',
        '@php artisan package:discover --ansi',
    ]);
});
