<?php

declare(strict_types=1);

namespace App\Support\Abstracts;

use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleConfiguration;
use Illuminate\Contracts\Container\Container;

/**
 * @template TEntry of object
 */
abstract class ConfigDrivenRegistry
{
    public function __construct(protected readonly Container $container) {}

    /**
     * @return class-string<TEntry>|null
     */
    protected function entryClass(string $key): ?string
    {
        $class = $this->configuredClasses()[$key] ?? null;

        return $class === null ? null : $this->availableClass($key, $class);
    }

    /**
     * @return array<string, class-string<TEntry>>
     */
    protected function entryClasses(): array
    {
        $classes = [];

        foreach ($this->configuredClasses() as $key => $configured) {
            $class = $this->availableClass($key, $configured);

            if ($class !== null) {
                $classes[$key] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param  class-string<TEntry>  $class
     * @return TEntry
     */
    protected function resolve(string $class): object
    {
        return $this->container->make($class);
    }

    abstract protected function configKey(): string;

    /**
     * @return class-string<TEntry>
     */
    abstract protected function contract(): string;

    /**
     * @param  class-string<TEntry>  $class
     */
    private function isAvailable(string $class): bool
    {
        return $this->container->make(ModuleAccess::class)->allows($class);
    }

    /**
     * @param  class-string<TEntry>  $class
     * @return class-string<TEntry>|null
     */
    private function availableClass(string $key, string $class): ?string
    {
        if ($this->isAvailable($class)) {
            return $class;
        }

        $class = $this->container->make(ModuleConfiguration::class)->replacedDefault("{$this->configKey()}.{$key}");

        if (!is_string($class) || !is_a($class, $this->contract(), true)) {
            return null;
        }

        return $this->isAvailable($class) ? $class : null;
    }

    /**
     * @return array<string, class-string<TEntry>>
     */
    private function configuredClasses(): array
    {
        $entries = config($this->configKey());

        if (!is_array($entries)) {
            return [];
        }

        $classes = [];

        foreach ($entries as $key => $class) {
            if (is_string($key) && is_string($class) && is_a($class, $this->contract(), true)) {
                $classes[$key] = $class;
            }
        }

        return $classes;
    }
}
