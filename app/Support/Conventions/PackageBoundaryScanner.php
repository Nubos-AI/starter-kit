<?php

declare(strict_types=1);

namespace App\Support\Conventions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PackageBoundaryScanner
{
    /** @var list<string> */
    private array $scannedDirectories = ['src', 'database'];

    /**
     * @return list<string>
     */
    public function scan(string $packagesRoot): array
    {
        $packages = $this->packages($packagesRoot);
        $namespaces = $this->namespaces($packages);
        $offenders = [];

        foreach ($packages as $name => $package) {
            foreach ($this->scannedDirectories as $directory) {
                $path = $package['path'].'/'.$directory;

                if (!is_dir($path)) {
                    continue;
                }

                $offenders = [...$offenders, ...$this->scanDirectory($path, $name, $package['declared'], $namespaces)];
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * @param  list<string>  $declared
     * @param  array<string, string>  $namespaces
     * @return list<string>
     */
    private function scanDirectory(string $directory, string $package, array $declared, array $namespaces): array
    {
        $offenders = [];

        foreach ($this->files($directory) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach ($this->references($contents) as $line => $referenced) {
                foreach ($referenced as $symbol) {
                    $owner = $this->ownerOf($symbol, $namespaces);

                    if ($owner === null || $owner === $package || in_array($owner, $declared, true)) {
                        continue;
                    }

                    $offenders[] = $file->getPathname().':'.$line.'  '.$package.' references '.$symbol.' owned by '.$owner;
                }
            }
        }

        return $offenders;
    }

    /**
     * @return array<int, list<string>>
     */
    private function references(string $contents): array
    {
        $found = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (preg_match_all('/\\\\?\bNubos\\\\+[A-Za-z0-9_\\\\]+/', $line, $matches) === 0) {
                continue;
            }

            $found[$index + 1] = array_values(array_unique(array_map(
                static fn (string $symbol): string => ltrim(str_replace('\\\\', '\\', $symbol), '\\'),
                $matches[0],
            )));
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $namespaces
     */
    private function ownerOf(string $symbol, array $namespaces): ?string
    {
        foreach ($namespaces as $prefix => $package) {
            if (str_starts_with($symbol, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{path: string, declared: list<string>, namespaces: list<string>}>  $packages
     * @return array<string, string>
     */
    private function namespaces(array $packages): array
    {
        $namespaces = [];

        foreach ($packages as $name => $package) {
            foreach ($package['namespaces'] as $prefix) {
                $namespaces[$prefix] = $name;
            }
        }

        uksort($namespaces, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $namespaces;
    }

    /**
     * @return array<string, array{path: string, declared: list<string>, namespaces: list<string>}>
     */
    private function packages(string $packagesRoot): array
    {
        $packages = [];

        foreach (glob($packagesRoot.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $manifest = $path.'/composer.json';

            if (!is_file($manifest)) {
                continue;
            }

            /** @var array{name?: string, require?: array<string, string>, require-dev?: array<string, string>, suggest?: array<string, string>, autoload?: array{'psr-4'?: array<string, string>}} $decoded */
            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $name = $decoded['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $optional = array_intersect(
                array_keys($decoded['require-dev'] ?? []),
                array_keys($decoded['suggest'] ?? []),
            );

            $packages[$name] = [
                'path' => $path,
                'declared' => [...array_keys($decoded['require'] ?? []), ...array_values($optional)],
                'namespaces' => array_keys($decoded['autoload']['psr-4'] ?? []),
            ];
        }

        return $packages;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function files(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
