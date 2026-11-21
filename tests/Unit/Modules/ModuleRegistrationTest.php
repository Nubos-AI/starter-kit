<?php

declare(strict_types=1);

use App\Actions\Modules\ForgetModuleAction;
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
    app()->forgetInstance(ForgetModuleAction::class);
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

it('runs the install command with the components of the module being registered already active and keeps them active afterwards', function (): void {
    ($this->catalogOver)(['nubos/example']);
    $registry = new StaticModuleRegistry;
    app()->instance(ModuleRegistry::class, $registry);

    $calls = $this->calls;
    Artisan::command('example:install', function () use ($calls, $registry): int {
        $calls[] = 'example:install active='.var_export($registry->has('nubos/example'), true);

        return 0;
    });

    $this->artisan('modules:register', ['package' => 'nubos/example'])->assertSuccessful();

    expect($this->calls->getArrayCopy())->toContain('example:install active=true')
        ->and($registry->has('nubos/example'))->toBeTrue();
});

it('deactivates the components of a module again when its install command fails', function (): void {
    ($this->catalogOver)(['nubos/example']);
    $registry = new StaticModuleRegistry;
    app()->instance(ModuleRegistry::class, $registry);

    Artisan::command('example:install', fn (): int => 1);

    $this->artisan('modules:register', ['package' => 'nubos/example'])->assertFailed();

    expect($registry->has('nubos/example'))->toBeFalse()
        ->and($this->calls->getArrayCopy())->not->toContain('store nubos/example');
});

it('deactivates the components of a module again when its install command throws', function (): void {
    ($this->catalogOver)(['nubos/example']);
    $registry = new StaticModuleRegistry;
    app()->instance(ModuleRegistry::class, $registry);

    Artisan::command('example:install', function (): int {
        throw new RuntimeException('install failed');
    });

    expect(fn () => $this->artisan('modules:register', ['package' => 'nubos/example'])->run())->toThrow(RuntimeException::class, 'install failed')
        ->and($registry->has('nubos/example'))->toBeFalse();
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
            ->expectsOutputToContain('The module nubos/example is not registered.')
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
        $this->databaseReachable = function (): void {
            $database = Mockery::mock(DatabaseManager::class);
            $database->shouldReceive('connection->getSchemaBuilder->hasTable')->with('modules')->andReturn(true);
            app()->instance(DatabaseManager::class, $database);
        };
        $this->databaseUnreachable = function (): void {
            $database = Mockery::mock(DatabaseManager::class);
            $database->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));
            app()->instance(DatabaseManager::class, $database);
        };
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

    it('runs the update of every registered module that requires or suggests a newly registered module', function (): void {
        ($this->catalogOver)(['nubos/example', 'nubos/provider', 'nubos/consumer', 'nubos/add-on']);
        ($this->registryHolding)(['nubos/example' => '2.0.0', 'nubos/provider' => '1.0.0', 'nubos/add-on' => '1.0.0']);

        $this->artisan('modules:sync')->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe(['register nubos/consumer', 'update nubos/add-on']);
    });

    it('does not update dependent modules when the new module fails to register', function (): void {
        ($this->catalogOver)(['nubos/provider', 'nubos/consumer', 'nubos/add-on']);
        ($this->registryHolding)(['nubos/provider' => '1.0.0', 'nubos/add-on' => '1.0.0']);
        $calls = $this->calls;
        Artisan::command('modules:register {package}', function () use ($calls): int {
            $calls[] = "register {$this->argument('package')}";

            return 1;
        });

        $this->artisan('modules:sync')->assertFailed();

        expect($this->calls->getArrayCopy())->toBe(['register nubos/consumer']);
    });

    it('leaves a module unregistered whose code stays installed after it was unregistered', function (): void {
        ($this->catalogOver)(['nubos/consumer', 'nubos/provider']);
        app()->instance(ModuleRegistry::class, new StaticModuleRegistry(['nubos/provider'], ['nubos/provider' => '1.0.0'], ['nubos/consumer']));
        ($this->databaseReachable)();

        $this->artisan('modules:sync')->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([]);
    });

    it('forgets the unregistration of a module whose code is gone so installing it again registers it', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        app()->instance(ModuleRegistry::class, new StaticModuleRegistry(['nubos/provider'], ['nubos/provider' => '1.0.0'], ['nubos/consumer', 'nubos/provider-gone']));
        ($this->databaseReachable)();
        $forgotten = new ArrayObject;
        app()->instance(ForgetModuleAction::class, new class($forgotten) extends ForgetModuleAction
        {
            public function __construct(private readonly ArrayObject $forgotten) {}

            public function execute(string $name): void
            {
                $this->forgotten[] = $name;
            }
        });

        $this->artisan('modules:sync')->assertSuccessful();

        expect($forgotten->getArrayCopy())->toBe(['nubos/consumer', 'nubos/provider-gone'])
            ->and($this->calls->getArrayCopy())->toBe([]);
    });

    it('fails and names every registered module whose code is no longer installed', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        ($this->registryHolding)(['nubos/provider' => '1.0.0', 'nubos/crm' => '1.0.10']);
        Log::spy();

        $this->artisan('modules:sync')
            ->expectsOutputToContain('nubos/crm')
            ->assertFailed();

        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $context === ['modules' => ['nubos/crm']]);
    });

    it('only warns about a registered module without code when Composer omitted the development packages on purpose', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        ($this->registryHolding)(['nubos/provider' => '1.0.0', 'nubos/demodata' => '1.0.0']);
        Log::spy();

        $this->artisan('modules:sync', ['--allow-missing-code' => true])
            ->expectsOutputToContain('nubos/demodata')
            ->doesntExpectOutputToContain('modules:unregister')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context === ['modules' => ['nubos/demodata']]);
        Log::shouldNotHaveReceived('error');
    });

    it('fails when the module registry cannot be read', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        ($this->databaseUnreachable)();
        Log::spy();

        $this->artisan('modules:sync')->assertFailed();

        expect($this->calls->getArrayCopy())->toBe([]);
    });

    it('skips the synchronisation without a database only when a Composer hook asks for it', function (): void {
        ($this->catalogOver)(['nubos/provider']);
        ($this->databaseUnreachable)();
        Log::spy();

        $this->artisan('modules:sync', ['--skip-without-database' => true])->assertSuccessful();

        expect($this->calls->getArrayCopy())->toBe([]);
        Log::shouldHaveReceived('warning')->once();
    });

    it('keeps updating the remaining modules when one update fails and reports the failure', function (): void {
        ($this->catalogOver)(['nubos/add-on', 'nubos/consumer', 'nubos/provider']);
        ($this->registryHolding)(['nubos/add-on' => '0.1.0', 'nubos/consumer' => '0.1.0', 'nubos/provider' => '0.1.0']);
        $this->failingUpdates = ['nubos/provider'];

        $this->artisan('modules:sync')->assertFailed();

        expect($this->calls->getArrayCopy())->toBe(['update nubos/provider', 'update nubos/consumer', 'update nubos/add-on']);
    });
});
