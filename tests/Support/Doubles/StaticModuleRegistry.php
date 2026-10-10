<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Modules\ModuleRegistry;

class StaticModuleRegistry extends ModuleRegistry
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(private readonly array $names = []) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->names;
    }
}
