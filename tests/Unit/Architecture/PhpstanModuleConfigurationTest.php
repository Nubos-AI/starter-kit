<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->root = storage_path('framework/testing/phpstan-'.uniqid());
    $this->files->ensureDirectoryExists($this->root);
    $this->files->copy(base_path('phpstan-packages.php'), "{$this->root}/phpstan-packages.php");

    /** @var callable(string, array<string, string>=):void */
    $this->installedModule = function (string $name, array $suggest = []): void {
        $this->files->ensureDirectoryExists("{$this->root}/vendor/nubos/{$name}");
        $this->files->put("{$this->root}/vendor/nubos/{$name}/composer.json", (string) json_encode(['suggest' => $suggest]));
    };

    /** @var callable():list<string> */
    $this->ignoredMessages = function (): array {
        $configuration = require "{$this->root}/phpstan-packages.php";

        return array_column($configuration['parameters']['ignoreErrors'] ?? [], 'message');
    };
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->root);
});

it('accepts the unknown classes of a suggested module that this application has not installed', function (string $message): void {
    ($this->installedModule)('application', ['nubos/admin' => 'Optional.']);

    [$pattern] = ($this->ignoredMessages)();

    expect(preg_match($pattern, $message))->toBe(1);
})->with([
    'an imported class' => ['Class Nubos\Admin\Support\OperatorTenant not found.'],
    'an implemented interface' => ['Class Nubos\Crm\Handler implements unknown interface Nubos\Admin\Contracts\Extension.'],
    'a call on the class' => ['Call to static method resolve() on an unknown class Nubos\Admin\Support\OperatorTenant.'],
]);

it('keeps reporting every other unknown class', function (): void {
    ($this->installedModule)('application', ['nubos/admin' => 'Optional.']);

    [$pattern] = ($this->ignoredMessages)();

    expect(preg_match($pattern, 'Class Nubos\Application\Support\Missing not found.'))->toBe(0)
        ->and(preg_match($pattern, 'Class Nubos\Administration\Missing not found.'))->toBe(0);
});

it('keeps reporting the classes of a suggested module that is installed', function (): void {
    ($this->installedModule)('application', ['nubos/admin' => 'Optional.']);
    ($this->installedModule)('admin');

    expect(($this->ignoredMessages)())->toBe([]);
});

it('keeps reporting the classes of a suggested module that is developed next door, because its sources are scanned', function (): void {
    ($this->installedModule)('application', ['nubos/admin' => 'Optional.']);
    $developmentLocation = str_replace(base_path().'/', '', ModuleRoots::location());
    $this->files->ensureDirectoryExists("{$this->root}/{$developmentLocation}/admin/src");

    expect(($this->ignoredMessages)())->toBe([]);
});

it('ignores nothing for a suggestion from outside the nubos modules', function (): void {
    ($this->installedModule)('application', ['acme/admin' => 'Optional.']);

    expect(($this->ignoredMessages)())->toBe([]);
});
