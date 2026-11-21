<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;

class ModuleCatalog
{
    private string $manifestFile = 'module.json';

    private int $apiVersion = 1;

    /** @var array<string, array<string, mixed>> */
    private array $manifests = [];

    /** @var array<string, list<string>> */
    private array $dependencies = [];

    /** @var list<string>|null */
    private ?array $modules = null;

    /** @var array<string, string|null> */
    private array $owners = [];

    public function __construct(
        private readonly PackageManifest $packages,
        private readonly Filesystem $files,
    ) {}

    public function has(string $module): bool
    {
        return in_array($module, $this->all(), true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->modules ??= $this->discover();
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(string $module): array
    {
        if (isset($this->manifests[$module])) {
            return $this->manifests[$module];
        }

        $path = $this->manifestPath($module) ?? throw new LogicException("Unknown module [{$module}].");

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['apiVersion'] ?? null) !== $this->apiVersion) {
            throw new LogicException("Unsupported module API in [{$module}].");
        }

        return $this->manifests[$module] = $manifest;
    }

    public function migrationPath(string $module): ?string
    {
        $root = $this->installPath($module);

        if ($root === null || !$this->files->isDirectory("{$root}/database/migrations")) {
            return null;
        }

        return "{$root}/database/migrations";
    }

    /**
     * @return list<string>
     */
    public function dependencies(string $module): array
    {
        if (isset($this->dependencies[$module])) {
            return $this->dependencies[$module];
        }

        $path = $this->installPath($module).'/composer.json';

        if (!$this->files->isFile($path)) {
            return $this->dependencies[$module] = [];
        }

        /** @var array{require?: array<string, string>, suggest?: array<string, string>} $composer */
        $composer = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);

        return $this->dependencies[$module] = array_keys([...$composer['require'] ?? [], ...$composer['suggest'] ?? []]);
    }

    /**
     * @param  object|class-string  $component
     */
    public function owner(object|string $component): ?string
    {
        $class = is_object($component) ? $component::class : $component;

        if (array_key_exists($class, $this->owners)) {
            return $this->owners[$class];
        }

        $file = (new ReflectionClass($class))->getFileName();
        $path = $file === false ? false : realpath($file);

        foreach ($this->all() as $module) {
            $root = $this->installPath($module);
            $root = $root === null ? false : realpath($root);

            if ($path !== false && $root !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return $this->owners[$class] = $module;
            }
        }

        return $this->owners[$class] = null;
    }

    public function version(string $module): ?string
    {
        $installed = $this->installedVersion($module);

        if ($installed !== null && !Str::startsWith($installed, 'dev-') && !Str::endsWith($installed, '-dev')) {
            return $installed;
        }

        $declared = $this->has($module) ? $this->manifest($module)['version'] ?? null : null;

        return is_string($declared) && $declared !== '' ? $declared : $installed;
    }

    protected function installedVersion(string $module): ?string
    {
        return InstalledVersions::isInstalled($module) ? InstalledVersions::getPrettyVersion($module) : null;
    }

    protected function installPath(string $package): ?string
    {
        return InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : null;
    }

    /**
     * @return list<string>
     */
    private function discover(): array
    {
        if (!$this->files->isFile($this->packages->manifestPath)) {
            return [];
        }

        /** @var array<string, mixed> $discovered */
        $discovered = (array) $this->files->getRequire($this->packages->manifestPath);

        $modules = array_values(array_filter(
            array_keys($discovered),
            fn (string $package): bool => $this->manifestPath($package) !== null,
        ));

        $ordered = [];

        foreach ($modules as $module) {
            $this->placeAfterDependencies($module, $modules, $ordered, []);
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $ordered
     * @param  list<string>  $visiting
     */
    private function placeAfterDependencies(string $module, array $modules, array &$ordered, array $visiting): void
    {
        if (in_array($module, $ordered, true) || in_array($module, $visiting, true)) {
            return;
        }

        foreach (array_intersect($this->dependencies($module), $modules) as $dependency) {
            $this->placeAfterDependencies($dependency, $modules, $ordered, [...$visiting, $module]);
        }

        $ordered[] = $module;
    }

    private function manifestPath(string $package): ?string
    {
        $root = $this->installPath($package);

        if ($root === null) {
            return null;
        }

        $path = $root.'/'.$this->manifestFile;

        return $this->files->isFile($path) ? $path : null;
    }
}
