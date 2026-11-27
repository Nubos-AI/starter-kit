<?php

declare(strict_types=1);

namespace App\Support\Modules;

class ModuleConfiguration
{
    public function mergeRecursive(string $path, string $key): void
    {
        /** @var array<array-key, mixed> $defaults */
        $defaults = require $path;
        $configured = config($key, []);

        config([$key => $this->merge($defaults, is_array($configured) ? $configured : [])]);
    }

    public function registerDefault(string $key, mixed $value): void
    {
        if (config()->has($key)) {
            return;
        }

        config([$key => $value]);
    }

    public function replaceDefault(string $key, mixed $default, mixed $value): void
    {
        if (config($key) !== $default) {
            return;
        }

        config([
            "modules.replaced_defaults.{$key}" => $default,
            $key => $value,
        ]);
    }

    public function replacedDefault(string $key): mixed
    {
        return config("modules.replaced_defaults.{$key}");
    }

    public function enabled(string $module, string $feature): bool
    {
        return config('modules.features.'.$module.'.'.$feature, true) !== false;
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $configured
     * @return array<array-key, mixed>
     */
    private function merge(array $defaults, array $configured): array
    {
        if (array_is_list($configured) && $configured !== []) {
            return $configured;
        }

        foreach ($configured as $key => $value) {
            $default = $defaults[$key] ?? null;
            $defaults[$key] = is_array($value) && is_array($default) && !array_is_list($default) ? $this->merge($default, $value) : $value;
        }

        return $defaults;
    }
}
