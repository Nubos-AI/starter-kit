<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\Module;

class ModuleRegistry
{
    /** @var list<string>|null */
    private ?array $registered = null;

    /** @var array<string, string|null>|null */
    private ?array $versions = null;

    /** @var list<string>|null */
    private ?array $withdrawnNames = null;

    /** @var list<string> */
    private array $activated = [];

    public function has(string $module): bool
    {
        return in_array($module, $this->activated, true) || in_array($module, $this->all(), true);
    }

    public function activate(string $module): void
    {
        $this->activated[] = $module;
    }

    public function deactivate(string $module): void
    {
        $this->activated = array_values(array_diff($this->activated, [$module]));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->registered === null) {
            /** @var list<string> $names */
            $names = Module::query()->registered()->orderBy('name')->pluck('name')->all();
            $this->registered = $names;
        }

        return $this->registered;
    }

    /**
     * @return list<string>
     */
    public function withdrawn(): array
    {
        if ($this->withdrawnNames === null) {
            /** @var list<string> $names */
            $names = Module::query()->withdrawn()->orderBy('name')->pluck('name')->all();
            $this->withdrawnNames = $names;
        }

        return $this->withdrawnNames;
    }

    public function version(string $module): ?string
    {
        if ($this->versions === null) {
            /** @var array<string, string|null> $versions */
            $versions = Module::query()->registered()->pluck('version', 'name')->all();
            $this->versions = $versions;
        }

        return $this->versions[$module] ?? null;
    }
}
