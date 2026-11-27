<?php

declare(strict_types=1);

namespace App\Support\Modules;

class RegisteredModuleComponents
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly ModuleRegistry $registry,
        private readonly ?ModuleAccess $access = null,
    ) {}

    /**
     * @param  object|class-string  $component
     */
    public function isActive(object|string $component): bool
    {
        $owner = $this->catalog->owner($component);

        if ($owner === null) {
            return true;
        }

        return $this->registry->has($owner) && ($this->access?->allows($component) ?? true);
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

    /**
     * @template TComponent of object
     *
     * @param  class-string<TComponent>  $contract
     * @param  iterable<mixed>  $components
     * @return ActiveModuleComponents<TComponent>
     */
    public function lazy(string $contract, iterable $components): ActiveModuleComponents
    {
        return new ActiveModuleComponents($this, $contract, $components);
    }
}
