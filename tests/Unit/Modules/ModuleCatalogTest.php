<?php

declare(strict_types=1);

use App\Support\Modules\ModuleCatalog;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->writtenManifests = [];

    /** @var callable(?array<string, mixed>):ModuleCatalog */
    $this->catalogOver = function (?array $manifest): ModuleCatalog {
        $path = sys_get_temp_dir().'/nubos-manifest-'.bin2hex(random_bytes(8)).'.php';

        if ($manifest !== null) {
            (new Filesystem)->put($path, '<?php return '.var_export($manifest, true).';');
            $this->writtenManifests[] = $path;
        }

        return new FixtureModuleCatalog($path);
    };

    /** @return array<string, mixed> */
    $this->discoveredPackages = static fn (): array => require base_path('bootstrap/cache/packages.php');
});

afterEach(function (): void {
    foreach ($this->writtenManifests as $path) {
        @unlink($path);
    }
});

it('reports only the discovered packages that ship a module manifest', function (): void {
    $catalog = new FixtureModuleCatalog;

    expect($catalog->has('nubos/example'))->toBeTrue()
        ->and($catalog->has('nubos/plain'))->toBeFalse()
        ->and($catalog->has('nubos/not-a-module'))->toBeFalse()
        ->and($catalog->all())->toBe(['nubos/example']);
});

it('leaves a discovered package without a module manifest out of the catalogue', function (): void {
    $discovered = array_keys(($this->discoveredPackages)());
    $catalogued = app(ModuleCatalog::class)->all();

    expect($discovered)->toContain('laravel/sanctum')
        ->and($catalogued)->not->toContain('laravel/sanctum')
        ->and($catalogued)->not->toContain('inertiajs/inertia-laravel')
        ->and(array_diff($catalogued, $discovered))->toBe([]);
});

it('reports a module as absent once the package discovery omits it', function (): void {
    $manifest = require base_path('tests/Fixtures/Modules/packages.php');
    unset($manifest['nubos/example']);

    $catalog = ($this->catalogOver)($manifest);

    expect($catalog->has('nubos/example'))->toBeFalse()
        ->and($catalog->all())->toBe([]);
});

it('reports a module as present once the package discovery lists it', function (): void {
    $catalog = ($this->catalogOver)(['nubos/example' => ['providers' => []]]);

    expect($catalog->has('nubos/example'))->toBeTrue()
        ->and($catalog->all())->toBe(['nubos/example']);
});

it('lists every module after the modules it requires or suggests', function (): void {
    $catalog = ($this->catalogOver)([
        'nubos/add-on' => ['providers' => []],
        'nubos/consumer' => ['providers' => []],
        'nubos/example' => ['providers' => []],
        'nubos/provider' => ['providers' => []],
    ]);

    expect($catalog->all())->toBe(['nubos/provider', 'nubos/consumer', 'nubos/add-on', 'nubos/example']);
});

it('keeps the discovery order for a dependency that is not installed', function (): void {
    $catalog = ($this->catalogOver)([
        'nubos/add-on' => ['providers' => []],
        'nubos/example' => ['providers' => []],
    ]);

    expect($catalog->all())->toBe(['nubos/add-on', 'nubos/example']);
});

it('reports no modules when the package discovery has not been written', function (): void {
    expect(($this->catalogOver)(null)->all())->toBe([]);
});

it('reads the install, uninstall, extension and option entries of a module manifest', function (): void {
    $manifest = (new FixtureModuleCatalog)->manifest('nubos/example');

    expect($manifest['install'])->toBe('example:install')
        ->and($manifest['uninstall'])->toBe('example:uninstall')
        ->and(array_column($manifest['extensions'], 'id'))->toBe(['nubos/example.tab'])
        ->and($manifest['options']['permissions.groups'])->toBe([['value' => 'example-templates', 'label' => 'Beispielvorlagen']]);
});

it('rejects a manifest lookup for a package that is not a module', function (): void {
    app(ModuleCatalog::class)->manifest('laravel/framework');
})->throws(LogicException::class, 'Unknown module [laravel/framework].');
