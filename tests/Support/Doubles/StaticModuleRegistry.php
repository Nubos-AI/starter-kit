<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Modules\ModuleRegistry;

class StaticModuleRegistry extends ModuleRegistry
{
    /**
     * @param  list<string>  $names
     * @param  array<string, string|null>  $versions
     */
    public function __construct(
        private readonly array $names = [],
        private readonly array $versions = [],
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->names;
    }

    public function version(string $module): ?string
    {
        return $this->versions[$module] ?? null;
    }
}
