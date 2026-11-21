<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string, string): list<string> */
    $this->moduleTestsMatching = static function (string $fileNames, string $pattern): array {
        $directories = ModuleRoots::directories('tests');

        if ($directories === []) {
            return [];
        }

        $offenders = [];

        foreach (Finder::create()->files()->in($directories)->name($fileNames) as $file) {
            if (preg_match($pattern, (string) file_get_contents((string) $file->getRealPath())) === 1) {
                $offenders[] = ModuleRoots::relativePath((string) $file->getRealPath());
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('keeps every module php test independent of the test classes of the application it is installed into', function (): void {
    expect(($this->moduleTestsMatching)('*.php', '/(?<![\w\\\\])\\\\?Tests\\\\/'))->toBe([]);
});

it('keeps every module frontend test independent of the test helpers of the application (@/tests), sharing only the shipped @/shared/testing', function (): void {
    expect(($this->moduleTestsMatching)('*.ts', "#(?:from\\s+|import\\(\\s*|vi\\.(?:mock|doMock|importActual|importMock)(?:<[^>]*>)?\\(\\s*)'@/tests/#"))->toBe([]);
});

it('keeps no copy of a shared frontend test helper in any module', function (): void {
    $shared = collect(glob(base_path('resources/js/shared/testing/*.ts')) ?: [])
        ->reject(static fn (string $path): bool => str_ends_with($path, '.spec.ts'));
    $sharedNames = $shared->map(static fn (string $path): string => basename($path))->all();
    $sharedHashes = $shared->map(static fn (string $path): string => (string) md5_file($path))->all();
    $directories = ModuleRoots::directories('tests/Frontend');

    $offenders = $directories === [] ? [] : collect(Finder::create()->files()->in($directories)->name('*.ts')->notName('*.spec.ts'))
        ->filter(static fn (SplFileInfo $file): bool => in_array($file->getFilename(), $sharedNames, true)
            || in_array(md5_file((string) $file->getRealPath()), $sharedHashes, true))
        ->map(static fn (SplFileInfo $file): string => ModuleRoots::relativePath((string) $file->getRealPath()))
        ->sort()
        ->values()
        ->all();

    expect($sharedNames)->toContain('selectStubs.ts', 'msw.ts', 'sectionNav.ts', 'setup.ts')
        ->and($offenders)->toBe([]);
});

it('boots every module frontend spec through the shared setup of the application', function (): void {
    $configuration = (string) file_get_contents(base_path('vitest.config.ts'));

    expect(Str::matchAll('#setupFiles:\\s*\\[([^\\]]*)\\]#', $configuration)->all())->toBe(["'./resources/js/shared/testing/setup.ts'"])
        ->and(($this->moduleTestsMatching)('*.ts', "#import\\s+'(?:\\.\\.?/)+(?:support/)?setup'#"))->toBe([]);
});

it('boots the test suite of every module through a bootstrap of its own', function (): void {
    $offenders = collect(ModuleRoots::all())
        ->filter(static fn (string $root): bool => is_file("{$root}/phpunit.xml"))
        ->reject(static fn (string $root): bool => str_contains((string) file_get_contents("{$root}/phpunit.xml"), 'bootstrap="tests/bootstrap.php"')
            && is_file("{$root}/tests/bootstrap.php"))
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});

it('renders no page through the Vite dev server in the test suite of the kit and of every module', function (): void {
    $switch = '<env name="INERTIA_SSR_ENABLED" value="false" force="true"/>';
    $offenders = collect(ModuleRoots::all())
        ->filter(static fn (string $root): bool => is_file("{$root}/phpunit.xml"))
        ->reject(static fn (string $root): bool => str_contains((string) file_get_contents("{$root}/phpunit.xml"), $switch))
        ->keys()
        ->all();

    expect(str_contains((string) file_get_contents(base_path('phpunit.xml')), $switch))->toBeTrue()
        ->and($offenders)->toBe([])
        ->and(config('inertia.ssr.enabled'))->toBeFalse();
});

it('keeps the search index and the log file of the running application out of the test suite of every module', function (): void {
    $offenders = collect(ModuleRoots::all())
        ->filter(static fn (string $root): bool => is_file("{$root}/phpunit.xml"))
        ->reject(static function (string $root): bool {
            $configuration = (string) file_get_contents("{$root}/phpunit.xml");

            return str_contains($configuration, '<env name="LOG_CHANNEL" value="null" force="true"/>')
                && str_contains($configuration, '<env name="SCOUT_DRIVER" value="null" force="true"/>');
        })
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});
