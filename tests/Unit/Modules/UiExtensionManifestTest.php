<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->knownPoints = function (): array {
        $files = collect([base_path('resources/js'), ...ModuleRoots::directories('resources/js')])
            ->flatMap(static fn (string $directory): array => File::allFiles($directory))
            ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'vue');
        $templates = $files->map(static fn (SplFileInfo $file): string => $file->getContents());
        $pages = $files
            ->map(static fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
            ->filter(static fn (string $path): bool => Str::startsWith($path, 'pages/'))
            ->map(static fn (string $path): string => Str::of($path)->after('pages/')->beforeLast('.vue')->toString());

        $hosts = $templates->flatMap(static fn (string $template): array => Str::matchAll('/\sextension-point="([^"]+)"/', $template)->all());
        $hostSuffixes = $templates
            ->flatMap(static fn (string $template): array => Str::matchAll('/:name="`\$\{(?:extensionPoint|extension\.point)\}([^`]*)`"/', $template)->all())
            ->when(
                $templates->contains(static fn (string $template): bool => str_contains($template, ':name="extensionPoint"')),
                static fn (Collection $suffixes): Collection => $suffixes->push(''),
            );
        $pageSuffixes = $templates->flatMap(static fn (string $template): array => Str::matchAll('/:name="`pages\.\$\{page\.component\}([^`]*)`"/', $template)->all());

        return $templates
            ->flatMap(static fn (string $template): array => Str::matchAll('/<UiExtensionPoint\b[^>]*?\sname="([^"]+)"/s', $template)->all())
            ->merge($hosts->crossJoin($hostSuffixes->unique())->map(static fn (array $pair): string => implode('', $pair)))
            ->merge($pages->crossJoin($pageSuffixes->unique())->map(static fn (array $pair): string => "pages.{$pair[0]}{$pair[1]}"))
            ->unique()
            ->values()
            ->all();
    };
});

it('hangs every component a module manifest contributes at a rendered extension point and ships it as a vue file of that module', function (): void {
    $knownPoints = ($this->knownPoints)();

    $misplaced = collect(ModuleRoots::files('module.json'))
        ->flatMap(static function (string $manifest) use ($knownPoints): array {
            $root = dirname($manifest);

            return collect(json_decode((string) file_get_contents($manifest), true)['extensions'] ?? [])
                ->reject(static fn (array $extension): bool => in_array($extension['point'] ?? null, $knownPoints, true)
                    && Str::is('components/*.vue', $extension['component'] ?? '')
                    && is_file("{$root}/resources/js/{$extension['component']}"))
                ->map(static fn (array $extension): string => (string) ($extension['id'] ?? ModuleRoots::relativePath($manifest)))
                ->all();
        })
        ->values()
        ->all();

    expect($knownPoints)->toContain('header.actions', 'pages.Welcome.after', 'tabs.record-detail-tabs.panels', 'menus.record-actions-menu')
        ->and($misplaced)->toBe([]);
});
