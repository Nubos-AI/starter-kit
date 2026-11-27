<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Modules\ModuleRegistry;

class StaticModuleRegistry extends ModuleRegistry
{
    /**
     * @param  list<string>  $names
     * @param  array<string, string|null>  $versions
     * @param  list<string>  $withdrawnNames
     */
    public function __construct(
        private readonly array $names = [],
        private readonly array $versions = [],
        private readonly array $withdrawnNames = [],
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->names;
    }

    /**
     * @return list<string>
     */
    public function withdrawn(): array
    {
        return $this->withdrawnNames;
    }

    public function version(string $module): ?string
    {
        return $this->versions[$module] ?? null;
    }
}
