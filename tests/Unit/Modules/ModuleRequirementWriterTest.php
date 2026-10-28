<?php

declare(strict_types=1);

use App\Support\Modules\ModuleRequirementWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/nubos-requirements-'.bin2hex(random_bytes(8));
    mkdir($this->directory);
    file_put_contents("{$this->directory}/composer.json", json_encode([
        'name' => 'acme/app',
        'require' => ['php' => '^8.3', 'laravel/framework' => '^13.17'],
        'repositories' => [['type' => 'composer', 'url' => 'https://packages.nubos.cloud']],
    ]));

    $this->manifest = fn (): array => json_decode((string) file_get_contents("{$this->directory}/composer.json"), true);
    $this->writer = new ModuleRequirementWriter(new Composer(new Filesystem, $this->directory));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('adds the chosen packages with the configured constraint and keeps the package service', function (): void {
    config(['modules.installable_constraint' => 'dev-main']);

    $this->writer->add(['nubos/pipelines', 'nubos/documents']);

    expect(($this->manifest)())->toBe([
        'name' => 'acme/app',
        'require' => [
            'php' => '^8.3',
            'laravel/framework' => '^13.17',
            'nubos/pipelines' => 'dev-main',
            'nubos/documents' => 'dev-main',
        ],
        'repositories' => [['type' => 'composer', 'url' => 'https://packages.nubos.cloud']],
    ]);
});

it('leaves a package that is already required at its constraint', function (): void {
    config(['modules.installable_constraint' => 'dev-main']);
    $this->writer->add(['nubos/pipelines']);
    config(['modules.installable_constraint' => '^2.0']);

    $this->writer->add(['nubos/pipelines']);

    expect(($this->manifest)()['require']['nubos/pipelines'])->toBe('dev-main');
});
