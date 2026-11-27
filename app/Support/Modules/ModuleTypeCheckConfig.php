<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Filesystem\Filesystem;

class ModuleTypeCheckConfig
{
    private string $file = 'tsconfig.modules.json';

    /** @var list<string> */
    private array $applicationSources = ['resources/js/**/*.ts', 'resources/js/**/*.tsx', 'resources/js/**/*.vue'];

    /** @var list<string> */
    private array $moduleSources = ['resources/js/**/*.ts', 'resources/js/**/*.vue', 'tests/Frontend/**/*.ts'];

    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly Filesystem $files,
    ) {}

    public function write(string $basePath): void
    {
        $include = $this->applicationSources;

        foreach ($this->catalog->all() as $module) {
            foreach ($this->moduleSources as $source) {
                $include[] = "vendor/{$module}/{$source}";
            }
        }

        $this->files->put(
            "{$basePath}/{$this->file}",
            json_encode(['extends' => './tsconfig.json', 'include' => $include], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }
}
