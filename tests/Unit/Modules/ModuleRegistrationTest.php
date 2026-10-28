<?php

declare(strict_types=1);

use App\Actions\Modules\RegisterModuleAction;
use App\Models\Module;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->calls = new ArrayObject;
    $this->failingPackages = new ArrayObject;

    $this->catalogOver = function (array $packages): void {
        $path = sys_get_temp_dir().'/nubos-manifest-'.bin2hex(random_bytes(8)).'.php';
        file_put_contents($path, '<?php return '.var_export(array_fill_keys($packages, ['providers' => []]), true).';');
        $this->manifests[] = $path;

        app()->instance(ModuleCatalog::class, new FixtureModuleCatalog($path));
    };
    $this->manifests = [];

    $calls = $this->calls;
    Artisan::command('migrate {--path=*} {--realpath} {--force}', function () use ($calls): int {
        $calls[] = 'migrate '.implode(',', $this->option('path')).($this->option('realpath') ? ' --realpath' : '');

        return 0;
    });
    Artisan::command('example:install', function () use ($calls): int {
        $calls[] = 'example:install';

        return 0;
    });

    app()->instance(RegisterModuleAction::class, new class(new FixtureModuleCatalog) extends RegisterModuleAction
    {
        public function execute(string $name): Module
        {
            return new Module(['name' => $name]);
        }
    });
});

afterEach(function (): void {
    foreach ($this->manifests as $path) {
        unlink($path);
    }

    app()->forgetInstance(ModuleCatalog::class);
    app()->forgetInstance(ModuleRegistry::class);
    app()->forgetInstance(RegisterModuleAction::class);
    app()->forgetInstance(DatabaseManager::class);
    Mockery::close();
});

it('migrates only the migrations the registered module ships', function (): void {
    ($this->catalogOver)(['nubos/example']);

    $this->artisan('modules:register', ['package' => 'nubos/example'])->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe([
        'migrate '.base_path('tests/Fixtures/Modules/nubos/example/database/migrations').' --realpath',
        'example:install',
    ]);
});

it('registers a module without migrations without running any migration', function (): void {
    ($this->catalogOver)(['nubos/provider']);

    $this->artisan('modules:register', ['package' => 'nubos/provider'])->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe([]);
});

it('keeps registering the remaining modules when one registration fails', function (): void {
    ($this->catalogOver)(['nubos/add-on', 'nubos/consumer', 'nubos/provider']);
    app()->instance(ModuleRegistry::class, new StaticModuleRegistry);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection->getSchemaBuilder->hasTable')->with('modules')->andReturn(true);
    app()->instance(DatabaseManager::class, $database);

    $calls = $this->calls;
    Artisan::command('modules:register {package}', function () use ($calls): int {
        $calls[] = "register {$this->argument('package')}";

        return $this->argument('package') === 'nubos/consumer' ? 1 : 0;
    });

    $this->artisan('modules:sync')->assertFailed();

    expect($this->calls->getArrayCopy())->toBe(['register nubos/provider', 'register nubos/consumer', 'register nubos/add-on']);
});

it('migrates every new module before it installs any of them', function (): void {
    ($this->catalogOver)(['nubos/example', 'nubos/provider']);
    app()->instance(ModuleRegistry::class, new StaticModuleRegistry);

    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection->getSchemaBuilder->hasTable')->with('modules')->andReturn(true);
    app()->instance(DatabaseManager::class, $database);

    $calls = $this->calls;
    Artisan::command('modules:register {package}', function () use ($calls): int {
        $calls[] = "register {$this->argument('package')}";

        return 0;
    });

    $this->artisan('modules:sync')->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe([
        'migrate '.base_path('tests/Fixtures/Modules/nubos/example/database/migrations').' --realpath',
        'register nubos/example',
        'register nubos/provider',
    ]);
});
