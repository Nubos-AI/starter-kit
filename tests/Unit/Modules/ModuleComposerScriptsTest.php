<?php

declare(strict_types=1);

use App\Support\Modules\ModuleComposerScripts;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->fixture = sys_get_temp_dir().'/nubos-module-scripts-'.bin2hex(random_bytes(8));

    $this->writeJson = function (string $path, array $content): void {
        file_put_contents($path, json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    };

    $this->artisanCalls = fn (): array => is_file($this->fixture.'/artisan-calls')
        ? file($this->fixture.'/artisan-calls', FILE_IGNORE_NEW_LINES)
        : [];

    $this->composerScripts = fn (): array => json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['scripts'];

    $this->files->mkdir([$this->fixture.'/module', $this->fixture.'/second', $this->fixture.'/library', $this->fixture.'/bootstrap/cache']);
    ($this->writeJson)($this->fixture.'/module/composer.json', ['name' => 'fixture/module', 'version' => '1.0.0']);
    ($this->writeJson)($this->fixture.'/module/module.json', ['apiVersion' => 1, 'uninstall' => 'fixture:uninstall']);
    ($this->writeJson)($this->fixture.'/second/composer.json', ['name' => 'fixture/second', 'version' => '1.0.0']);
    ($this->writeJson)($this->fixture.'/second/module.json', ['apiVersion' => 1, 'uninstall' => 'second:uninstall']);
    ($this->writeJson)($this->fixture.'/library/composer.json', ['name' => 'fixture/library', 'version' => '1.0.0', 'autoload' => ['files' => ['functions.php']]]);
    file_put_contents($this->fixture.'/library/functions.php', '<?php');
    ($this->writeJson)($this->fixture.'/composer.json', [
        'name' => 'fixture/root',
        'require-dev' => ['fixture/module' => '*', 'fixture/second' => '*', 'fixture/library' => '*'],
        'autoload' => ['psr-4' => ['App\\Support\\Modules\\' => dirname(__DIR__, 3).'/app/Support/Modules']],
        'repositories' => [
            ['type' => 'path', 'url' => 'module'],
            ['type' => 'path', 'url' => 'second'],
            ['type' => 'path', 'url' => 'library'],
            ['packagist.org' => false],
        ],
        'scripts' => [
            'post-autoload-dump' => [
                '@php -r "@unlink(\'bootstrap/cache/packages.php\');"',
                ModuleComposerScripts::class.'::postAutoloadDump',
            ],
            'pre-operations-exec' => [ModuleComposerScripts::class.'::preOperationsExec'],
        ],
    ]);
    file_put_contents($this->fixture.'/artisan', <<<'SCRIPT'
<?php
require __DIR__.'/vendor/autoload.php';
$manifest = getenv('APP_PACKAGES_CACHE') ?: __DIR__.'/bootstrap/cache/packages.php';
if (!is_file($manifest)) {
    $installed = json_decode(file_get_contents(__DIR__.'/vendor/composer/installed.json'), true);
    file_put_contents($manifest, '<?php return '.var_export(array_fill_keys(array_column($installed['packages'], 'name'), []), true).';');
}
foreach (array_keys(require $manifest) as $package) {
    if (!is_dir(__DIR__.'/vendor/'.$package)) {
        fwrite(STDERR, "Class provider of {$package} not found.");
        exit(1);
    }
}
file_put_contents(__DIR__.'/artisan-calls', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
exit(is_file(__DIR__.'/fail-artisan') ? 23 : 0);
SCRIPT);

    $this->composer = function (array $arguments): Process {
        $process = new Process(['composer', ...$arguments, '--no-interaction', '--no-progress'], $this->fixture);
        $process->setTimeout(120);
        $process->run();

        return $process;
    };

    $installed = ($this->composer)(['install']);
    expect($installed->isSuccessful())->toBeTrue($installed->getErrorOutput());
});

afterEach(function (): void {
    $this->files->remove($this->fixture);
});

it('wires the module hooks into the Composer scripts of the starterkit', function (): void {
    expect(($this->composerScripts)()['post-autoload-dump'])->toContain(ModuleComposerScripts::class.'::postAutoloadDump')
        ->and(($this->composerScripts)()['post-autoload-dump'])->not->toContain('@php artisan modules:sync')
        ->and(($this->composerScripts)()['pre-operations-exec'])->toBe([ModuleComposerScripts::class.'::preOperationsExec'])
        ->and(($this->composerScripts)())->not->toHaveKey('pre-package-uninstall');
});

it('synchronises the module registry without prompting when Composer runs non-interactively', function (): void {
    expect(($this->artisanCalls)())->toBe(['modules:sync --skip-without-database --no-interaction']);
});

it('unregisters a module through the real composer remove event before unlinking it', function (): void {
    $process = ($this->composer)(['remove', '--dev', 'fixture/module']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(($this->artisanCalls)())->toContain('modules:unregister fixture/module --no-interaction')
        ->and(is_dir($this->fixture.'/vendor/fixture/module'))->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('vendor/bin/sail npm run build');
});

it('unregisters every module when one composer remove unlinks several of them', function (): void {
    $process = ($this->composer)(['remove', '--dev', 'fixture/module', 'fixture/second']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(($this->artisanCalls)())->toContain('modules:unregister fixture/module --no-interaction')
        ->and(($this->artisanCalls)())->toContain('modules:unregister fixture/second --no-interaction')
        ->and(is_dir($this->fixture.'/vendor/fixture/module'))->toBeFalse()
        ->and(is_dir($this->fixture.'/vendor/fixture/second'))->toBeFalse();
});

it('leaves the cached package manifest of the application untouched while unregistering', function (): void {
    $manifest = $this->fixture.'/bootstrap/cache/packages.php';
    file_put_contents($manifest, "<?php return ['fixture/module' => [], 'fixture/second' => []];");

    touch($this->fixture.'/fail-artisan');
    ($this->composer)(['remove', '--dev', 'fixture/module']);

    expect((string) file_get_contents($manifest))->toBe("<?php return ['fixture/module' => [], 'fixture/second' => []];");
});

it('aborts the real composer removal when unregistering fails', function (): void {
    touch($this->fixture.'/fail-artisan');

    $process = ($this->composer)(['remove', '--dev', 'fixture/module']);

    expect($process->isSuccessful())->toBeFalse()
        ->and(($this->artisanCalls)())->toContain('modules:unregister fixture/module --no-interaction')
        ->and(is_dir($this->fixture.'/vendor/fixture/module'))->toBeTrue();
});

it('unregisters dependent modules first while every package Composer removes alongside them is still on disk', function (): void {
    $this->files->mkdir($this->fixture.'/dependent');
    ($this->writeJson)($this->fixture.'/dependent/composer.json', [
        'name' => 'fixture/dependent',
        'version' => '1.0.0',
        'require' => ['fixture/library' => '*', 'fixture/module' => '*'],
    ]);
    ($this->writeJson)($this->fixture.'/dependent/module.json', ['apiVersion' => 1]);
    $root = json_decode((string) file_get_contents($this->fixture.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $root['require-dev'] = ['fixture/dependent' => '*', 'fixture/second' => '*'];
    $root['repositories'] = [['type' => 'path', 'url' => 'dependent'], ...$root['repositories']];
    ($this->writeJson)($this->fixture.'/composer.json', $root);
    $updated = ($this->composer)(['update']);
    expect($updated->isSuccessful())->toBeTrue($updated->getErrorOutput());
    unlink($this->fixture.'/artisan-calls');

    $process = ($this->composer)(['remove', '--dev', 'fixture/dependent']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(array_values(array_filter(($this->artisanCalls)(), static fn (string $call): bool => str_starts_with($call, 'modules:unregister'))))->toBe([
            'modules:unregister fixture/dependent --no-interaction',
            'modules:unregister fixture/module --no-interaction',
        ])
        ->and(is_dir($this->fixture.'/vendor/fixture/dependent'))->toBeFalse()
        ->and(is_dir($this->fixture.'/vendor/fixture/module'))->toBeFalse()
        ->and(is_dir($this->fixture.'/vendor/fixture/library'))->toBeFalse();
});

it('leaves packages without a module manifest alone', function (): void {
    $process = ($this->composer)(['remove', '--dev', 'fixture/library']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(($this->artisanCalls)())->not->toContain('modules:unregister fixture/library --no-interaction')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('npm run build');
});

it('does not erase data when a no-dev deployment merely omits a development module', function (): void {
    $process = ($this->composer)(['install', '--no-dev']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(($this->artisanCalls)())->not->toContain('modules:unregister fixture/module --no-interaction');
});

it('lets the module registry keep a development module a no-dev deployment omits instead of failing the deployment', function (): void {
    $process = ($this->composer)(['install', '--no-dev']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(($this->artisanCalls)())->toContain('modules:sync --skip-without-database --allow-missing-code --no-interaction');
});
