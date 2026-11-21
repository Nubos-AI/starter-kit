<?php

declare(strict_types=1);

namespace App\Support\Abstracts;

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
        return $this->entryClasses()[$key] ?? null;
    }

    /**
     * @return array<string, class-string<TEntry>>
     */
    protected function entryClasses(): array
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
}
