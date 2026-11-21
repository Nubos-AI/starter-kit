<?php

declare(strict_types=1);

namespace App\Support\Modules;

class RegisteredModuleComponents
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * @param  object|class-string  $component
     */
    public function isActive(object|string $component): bool
    {
        $owner = $this->catalog->owner($component);

        return $owner === null || $this->registry->has($owner);
    }

    /**
     * @template TComponent of object
     *
     * @param  iterable<TComponent>  $components
     * @return list<TComponent>
     */
    public function only(iterable $components): array
    {
        $active = [];

        foreach ($components as $component) {
            if ($this->isActive($component)) {
                $active[] = $component;
            }
        }

        return $active;
    }
}
