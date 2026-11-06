<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Composer;

class ModuleRequirementWriter
{
    public function __construct(private readonly Composer $composer) {}

    /**
     * @param  list<string>  $packages
     */
    public function add(array $packages): void
    {
        $constraint = (string) config('modules.installable_constraint');

        $this->composer->modify(fn (array $manifest): array => [
            ...$manifest,
            'require' => ($manifest['require'] ?? []) + array_fill_keys($packages, $constraint),
        ]);
    }
}
