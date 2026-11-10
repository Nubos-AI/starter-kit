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

it('catalogues only packages the real discovery lists', function (): void {
    expect(array_diff(app(ModuleCatalog::class)->all(), array_keys(($this->discoveredPackages)())))->toBe([]);
});

it('reports a module exactly while the package discovery lists it', function (array $manifest, bool $isPresent, array $expectedModules): void {
    $catalog = ($this->catalogOver)($manifest);

    expect($catalog->has('nubos/example'))->toBe($isPresent)
        ->and($catalog->all())->toBe($expectedModules);
})->with([
    'listed' => [['nubos/example' => ['providers' => []]], true, ['nubos/example']],
    'omitted' => [
        function (): array {
            $manifest = require base_path('tests/Fixtures/Modules/packages.php');
            unset($manifest['nubos/example']);

            return $manifest;
        },
        false,
        [],
    ],
]);

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

it('reads the package discovery and the module manifests only once per catalogue', function (): void {
    $files = new class extends Filesystem
    {
        /** @var list<string> */
        public array $reads = [];

        public function getRequire($path, array $data = [])
        {
            $this->reads[] = "require:{$path}";

            return parent::getRequire($path, $data);
        }

        public function isFile($file)
        {
            $this->reads[] = "isFile:{$file}";

            return parent::isFile($file);
        }
    };
    $catalog = new FixtureModuleCatalog(null, $files);

    $answers = [$catalog->has('nubos/example')];
    $readsAfterFirstUse = $files->reads;
    $answers[] = $catalog->has('nubos/plain');
    $answers[] = $catalog->all();
    $answers[] = $catalog->has('nubos/example');

    expect($answers)->toBe([true, false, ['nubos/example'], true])
        ->and($readsAfterFirstUse)->not->toBe([])
        ->and($files->reads)->toBe($readsAfterFirstUse);
});

it('reads the install, uninstall, update, extension and option entries of a module manifest', function (): void {
    $manifest = (new FixtureModuleCatalog)->manifest('nubos/example');

    expect($manifest['install'])->toBe('example:install')
        ->and($manifest['uninstall'])->toBe('example:uninstall')
        ->and($manifest['update'])->toBe('example:update')
        ->and(array_column($manifest['extensions'], 'id'))->toBe(['nubos/example.tab'])
        ->and($manifest['options']['permissions.groups'])->toBe([['value' => 'example-templates', 'label' => 'Beispielvorlagen']]);
});

it('takes the version a module manifest declares over the version Composer installed', function (): void {
    $catalog = new FixtureModuleCatalog;

    expect($catalog->version('nubos/example'))->toBe('2.0.0');
});

it('falls back to the installed version when the module manifest declares none', function (): void {
    $catalog = ($this->catalogOver)(['nubos/provider' => ['providers' => []]]);

    expect($catalog->version('nubos/provider'))->toBe('1.0.0')
        ->and($catalog->version('nubos/plain'))->toBeNull();
});

it('rejects a manifest lookup for a package that is not a module', function (): void {
    app(ModuleCatalog::class)->manifest('laravel/framework');
})->throws(LogicException::class, 'Unknown module [laravel/framework].');
