<?php

declare(strict_types=1);

use App\Support\Conventions\PackageBoundaryScanner;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->boundaryPackage = static function (string $root, string $name, array $composer, array $files): void {
        $path = $root.'/'.basename($name);
        mkdir($path.'/src', 0777, true);
        file_put_contents($path.'/composer.json', json_encode(['name' => $name, ...$composer], JSON_THROW_ON_ERROR));

        foreach ($files as $file => $contents) {
            file_put_contents($path.'/src/'.$file, $contents);
        }
    };
});

test('no package reaches across a package boundary it does not declare', function (): void {
    expect(app(PackageBoundaryScanner::class)->scan(base_path('packages/nubos')))->toBe([]);
});

test('the scanner reports a reference to a package that is not declared', function (): void {
    $root = sys_get_temp_dir().'/package-boundary-'.bin2hex(random_bytes(6));
    mkdir($root);

    ($this->boundaryPackage)($root, 'nubos/consumer', ['autoload' => ['psr-4' => ['Nubos\\Consumer\\' => 'src/']]], []);
    ($this->boundaryPackage)($root, 'nubos/offender', [
        'autoload' => ['psr-4' => ['Nubos\\Offender\\' => 'src/']],
    ], ['Broken.php' => "<?php\n\nuse Nubos\\Consumer\\Thing;\n"]);

    $offenders = app(PackageBoundaryScanner::class)->scan($root);

    expect($offenders)->toHaveCount(1)
        ->and($offenders[0])->toContain('nubos/offender references Nubos\\Consumer\\Thing owned by nubos/consumer');
});

test('the scanner accepts a required package and an optional one declared through require-dev and suggest', function (): void {
    $root = sys_get_temp_dir().'/package-boundary-'.bin2hex(random_bytes(6));
    mkdir($root);

    ($this->boundaryPackage)($root, 'nubos/host', ['autoload' => ['psr-4' => ['Nubos\\Host\\' => 'src/']]], []);
    ($this->boundaryPackage)($root, 'nubos/hard', [
        'require' => ['nubos/host' => '@dev'],
        'autoload' => ['psr-4' => ['Nubos\\Hard\\' => 'src/']],
    ], ['Fine.php' => "<?php\n\nuse Nubos\\Host\\Thing;\n"]);
    ($this->boundaryPackage)($root, 'nubos/plugin', [
        'require-dev' => ['nubos/host' => '@dev'],
        'suggest' => ['nubos/host' => 'Adds the integration.'],
        'autoload' => ['psr-4' => ['Nubos\\Plugin\\' => 'src/']],
    ], ['Adapter.php' => "<?php\n\nuse Nubos\\Host\\Thing;\n"]);

    expect(app(PackageBoundaryScanner::class)->scan($root))->toBe([]);
});

test('the scanner rejects an optional dependency that is not also suggested', function (): void {
    $root = sys_get_temp_dir().'/package-boundary-'.bin2hex(random_bytes(6));
    mkdir($root);

    ($this->boundaryPackage)($root, 'nubos/host', ['autoload' => ['psr-4' => ['Nubos\\Host\\' => 'src/']]], []);
    ($this->boundaryPackage)($root, 'nubos/plugin', [
        'require-dev' => ['nubos/host' => '@dev'],
        'autoload' => ['psr-4' => ['Nubos\\Plugin\\' => 'src/']],
    ], ['Adapter.php' => "<?php\n\nuse Nubos\\Host\\Thing;\n"]);

    expect(app(PackageBoundaryScanner::class)->scan($root))->toHaveCount(1);
});
