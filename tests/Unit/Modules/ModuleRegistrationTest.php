<?php

declare(strict_types=1);

use App\Actions\Modules\RegisterModuleAction;
use App\Models\Module;
use App\Support\Modules\ModuleCatalog;
use App\Support\Modules\ModuleExecutionGate;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
    $this->updateHookExitCode = 0;
    Artisan::command('example:update', function () use ($calls): int {
        $calls[] = 'example:update';

        return test()->updateHookExitCode;
    });

    app()->instance(RegisterModuleAction::class, new class(new FixtureModuleCatalog, $calls) extends RegisterModuleAction
    {
        public function __construct(FixtureModuleCatalog $catalog, private readonly ArrayObject $calls)
        {
            parent::__construct($catalog);
        }

        public function execute(string $name): Module
        {
            $this->calls[] = "store {$name}";

            return new Module(['name' => $name]);
        }
    });

    $this->registryHolding = function (array $versions): void {
        app()->instance(ModuleRegistry::class, new StaticModuleRegistry(array_keys($versions), $versions));

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection->getSchemaBuilder->hasTable')->with('modules')->andReturn(true);
        app()->instance(DatabaseManager::class, $database);
    };
    $this->exampleMigrations = 'migrate '.base_path('tests/Fixtures/Modules/nubos/example/database/migrations').' --realpath';

    $this->executionGate = Mockery::mock(ModuleExecutionGate::class);
    $this->executionGate->shouldReceive('enabled')->byDefault()->andReturnTrue();
    app()->instance(ModuleExecutionGate::class, $this->executionGate);
});

afterEach(function (): void {
    foreach ($this->manifests as $path) {
        unlink($path);
    }

    app()->forgetInstance(ModuleCatalog::class);
    app()->forgetInstance(ModuleRegistry::class);
    app()->forgetInstance(RegisterModuleAction::class);
    app()->forgetInstance(DatabaseManager::class);
    app()->forgetInstance(ModuleExecutionGate::class);
    Mockery::close();
});

it('migrates only the migrations the registered module ships', function (): void {
    ($this->catalogOver)(['nubos/example']);

    $this->artisan('modules:register', ['package' => 'nubos/example'])->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe([
        'migrate '.base_path('tests/Fixtures/Modules/nubos/example/database/migrations').' --realpath',
        'example:install',
        'store nubos/example',
    ]);
});

it('registers a module without migrations without running any migration', function (): void {
    ($this->catalogOver)(['nubos/provider']);

    $this->artisan('modules:register', ['package' => 'nubos/provider'])->assertSuccessful();

    expect($this->calls->getArrayCopy())->toBe(['store nubos/provider']);
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

describe('updating a registered module', function (): void {
    it('migrates a module whose version changed, runs its update command and stores the new version last', function (): void {
        ($this->catalogOver)(['nubos/example']);
        ($this->registryHolding)(['nubos/example' => '1.0.0']);

        $this->artisan('modules:update', ['package' => 'nubos/example'])->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([$this->exampleMigrations, 'example:update', 'store nubos/example']);
    });

    it('stores the new version of a module without an update command after its migrations', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        ($this->registryHolding)(['nubos/provider' => '0.9.0']);

        $this->artisan('modules:update', ['package' => 'nubos/provider'])->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe(['store nubos/provider']);
    });

    it('keeps the stored version and logs both versions when the update command fails', function (): void {
        ($this->catalogOver)(['nubos/example']);
        ($this->registryHolding)(['nubos/example' => '1.0.0']);
        $this->updateHookExitCode = 1;
        Log::spy();

        $this->artisan('modules:update', ['package' => 'nubos/example'])->assertFailed();

        expect($this->calls->getArrayCopy())->toBe([$this->exampleMigrations, 'example:update']);
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
            'module' => 'nubos/example',
            'from_version' => '1.0.0',
            'to_version' => '2.0.0',
            'step' => 'example:update',
        ]);
    });

    it('keeps the stored version and logs the failure when the update command throws', function (): void {
        ($this->catalogOver)(['nubos/example']);
        ($this->registryHolding)(['nubos/example' => '1.0.0']);
        Artisan::command('example:update', function (): int {
            throw new RuntimeException('hook broke');
        });
        Log::spy();

        $this->artisan('modules:update', ['package' => 'nubos/example'])->assertFailed();

        expect($this->calls->getArrayCopy())->toBe([$this->exampleMigrations]);
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context['module'] === 'nubos/example'
            && $context['from_version'] === '1.0.0'
            && $context['to_version'] === '2.0.0'
            && $context['exception'] instanceof RuntimeException);
    });

    it('refuses to update a module that is not registered before it migrates anything', function (): void {
        ($this->catalogOver)(['nubos/example']);
        ($this->registryHolding)([]);

        $this->artisan('modules:update', ['package' => 'nubos/example'])
            ->expectsOutputToContain('Das Modul nubos/example ist nicht registriert.')
            ->assertFailed();

        expect($this->calls->getArrayCopy())->toBe([]);
    });

    it('leaves a disabled module untouched so an update never switches it back on', function (): void {
        ($this->catalogOver)(['nubos/example']);
        ($this->registryHolding)(['nubos/example' => '1.0.0']);
        $this->executionGate->shouldReceive('enabled')->with('nubos/example')->andReturnFalse();
        Log::spy();

        $this->artisan('modules:update', ['package' => 'nubos/example'])->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([]);
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context['module'] === 'nubos/example');
    });

    it('refuses an update hook that is not the update command of a module before it runs anything', function (string $hook): void {
        app()->instance(ModuleCatalog::class, new class($hook) extends FixtureModuleCatalog
        {
            public function __construct(private readonly string $hook)
            {
                parent::__construct();
            }

            public function manifest(string $module): array
            {
                return [...parent::manifest($module), 'update' => $this->hook];
            }
        });
        ($this->registryHolding)(['nubos/example' => '1.0.0']);
        Log::spy();

        $this->artisan('modules:update', ['package' => 'nubos/example'])->assertFailed();

        expect($this->calls->getArrayCopy())->toBe([]);
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context['step'] === $hook);
    })->with(['example:install', 'migrate:fresh', 'db:wipe --force']);

    it('updates every registered module in dependency order when no module is named', function (): void {
        ($this->catalogOver)(['nubos/example', 'nubos/provider', 'nubos/consumer']);
        ($this->registryHolding)(['nubos/consumer' => '1.0.0', 'nubos/example' => '2.0.0']);

        $this->artisan('modules:update')->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([$this->exampleMigrations, 'example:update', 'store nubos/example', 'store nubos/consumer']);
    });
});

describe('synchronising module versions', function (): void {
    beforeEach(function (): void {
        $calls = $this->calls;
        Artisan::command('modules:register {package}', function () use ($calls): int {
            $calls[] = "register {$this->argument('package')}";

            return 0;
        });
        $this->failingUpdates = [];
        $failing = &$this->failingUpdates;
        Artisan::command('modules:update {package?}', function () use ($calls, &$failing): int {
            $calls[] = "update {$this->argument('package')}";

            return in_array($this->argument('package'), $failing, true) ? 1 : 0;
        });
    });

    it('updates only the registered modules whose installed version differs from the stored one', function (): void {
        ($this->catalogOver)(['nubos/example', 'nubos/provider', 'nubos/consumer']);
        ($this->registryHolding)(['nubos/example' => '1.0.0', 'nubos/provider' => '1.0.0']);

        $this->artisan('modules:sync')->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe(['update nubos/example', 'register nubos/consumer']);
    });

    it('does nothing on a second run once every stored version matches', function (): void {
        ($this->catalogOver)(['nubos/example', 'nubos/provider']);
        ($this->registryHolding)(['nubos/example' => '2.0.0', 'nubos/provider' => '1.0.0']);

        $this->artisan('modules:sync')->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([]);
    });

    it('keeps synchronising the remaining modules when the manifest of one cannot be read and logs it', function (): void {
        app()->instance(ModuleCatalog::class, new class extends FixtureModuleCatalog
        {
            public function all(): array
            {
                return ['nubos/example', 'nubos/provider'];
            }

            public function version(string $module): ?string
            {
                return $module === 'nubos/example' ? throw new JsonException('Syntax error') : '2.0.0';
            }
        });
        ($this->registryHolding)(['nubos/example' => '1.0.0', 'nubos/provider' => '1.0.0']);
        Log::spy();

        $this->artisan('modules:sync')->assertFailed();

        expect($this->calls->getArrayCopy())->toBe(['update nubos/provider']);
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context['module'] === 'nubos/example' && $context['exception'] instanceof JsonException);
    });

    it('keeps updating the remaining modules when one update fails and reports the failure', function (): void {
        ($this->catalogOver)(['nubos/add-on', 'nubos/consumer', 'nubos/provider']);
        ($this->registryHolding)(['nubos/add-on' => '0.1.0', 'nubos/consumer' => '0.1.0', 'nubos/provider' => '0.1.0']);
        $this->failingUpdates = ['nubos/provider'];

        $this->artisan('modules:sync')->assertFailed();

        expect($this->calls->getArrayCopy())->toBe(['update nubos/provider', 'update nubos/consumer', 'update nubos/add-on']);
    });
});
