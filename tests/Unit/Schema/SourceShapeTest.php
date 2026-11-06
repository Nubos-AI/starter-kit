<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->phpSourcePaths = static function (): array {
        $directories = [base_path('app'), base_path('database'), base_path('tests')];

        foreach (ModuleRoots::all() as $package) {
            foreach (['src', 'database', 'tests'] as $directory) {
                if (is_dir($package.'/'.$directory)) {
                    $directories[] = $package.'/'.$directory;
                }
            }
        }

        $finder = (new Finder)
            ->files()
            ->in($directories)
            ->name('*.php');

        return collect($finder)
            ->map(fn ($file): string => (string) $file->getRealPath())
            ->values()
            ->all();
    };

    $this->classConstantDeclarations = static function (array $paths): array {
        return collect($paths)
            ->flatMap(function (string $path): array {
                $lines = file($path, FILE_IGNORE_NEW_LINES);

                return collect($lines === false ? [] : $lines)
                    ->filter(fn (string $line): bool => Str::isMatch('/^\s*(private|protected|public)?\s*const\s+[A-Z]/', $line))
                    ->map(fn (string $line, int $index): string => Str::after($path, base_path().'/').':'.($index + 1))
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    };
});

it('declares no class constant anywhere in app database tests or packages', function (): void {
    $paths = ($this->phpSourcePaths)();
    $offenders = ($this->classConstantDeclarations)($paths);

    expect($paths)->not->toBeEmpty()
        ->and($offenders)->toBe(
            [],
            'Ein endlicher Wertebereich wird ein Enum, ein Stellwert eine Klasseneigenschaft oder ein config-Eintrag. Verletzende Stellen: '.implode(', ', $offenders),
        );
});

it('recognises a class constant regardless of its visibility', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'shape');
    file_put_contents($path, implode(PHP_EOL, [
        '<?php',
        '    private const FIRST_KEYS = [];',
        '    protected const SECOND = 1;',
        '    public const THIRD = 2;',
        '    const FOURTH = 3;',
        '    private array $keys = [];',
    ]));

    expect(($this->classConstantDeclarations)([$path]))->toHaveCount(4);

    unlink($path);
});
