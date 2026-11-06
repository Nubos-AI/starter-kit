<?php

declare(strict_types=1);

namespace App\Support\Localization;

use Illuminate\Translation\FileLoader;
use RuntimeException;

class JsonTranslationLoader extends FileLoader
{
    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    protected function loadPaths(array $paths, $locale, $group): array
    {
        $lines = parent::loadPaths($paths, $locale, $group);

        foreach ($paths as $path) {
            $lines = array_replace_recursive($lines, $this->readJson("{$path}/{$locale}/{$group}.json"));

            if (in_array($group, ['auth', 'pagination', 'passwords', 'validation'], true)) {
                $catalogue = $this->readJson("{$path}/{$locale}/i18n.json");
                $lines = array_replace_recursive($lines, $catalogue['framework'][$group] ?? []);
            }
        }

        return $lines;
    }

    /** @return array<string, string> */
    protected function loadJsonPaths($locale): array
    {
        $lines = parent::loadJsonPaths($locale);

        foreach ($this->paths as $path) {
            $entries = $this->readJson("{$path}/{$locale}/i18n.json")['framework']['json'] ?? [];
            $lines = array_replace($lines, array_column($entries, 'value', 'key'));
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    protected function loadNamespaceOverrides(array $lines, $locale, $group, $namespace): array
    {
        $lines = parent::loadNamespaceOverrides($lines, $locale, $group, $namespace);

        foreach ($this->paths as $path) {
            $lines = array_replace_recursive($lines, $this->readJson("{$path}/vendor/{$namespace}/{$locale}/{$group}.json"));
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!$this->files->exists($path)) {
            return [];
        }

        $contents = $this->files->get($path);
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !str_starts_with(ltrim($contents), '{')) {
            throw new RuntimeException("Translation catalogue [{$path}] must be a JSON object.");
        }

        return $decoded;
    }
}
