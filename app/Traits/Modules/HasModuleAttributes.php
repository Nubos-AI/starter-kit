<?php

declare(strict_types=1);

namespace App\Traits\Modules;

use Illuminate\Container\Container;

trait HasModuleAttributes
{
    public function initializeHasModuleAttributes(): void
    {
        /** @var array{fillable?: list<string>, casts?: array<string, string>, defaults?: array<string, mixed>} $extension */
        $extension = $this->moduleAttributes();

        $this->mergeFillable($extension['fillable'] ?? []);
        $this->mergeCasts($extension['casts'] ?? []);
        $this->setRawAttributes([...($extension['defaults'] ?? []), ...$this->getAttributes()]);
    }

    /** @return array<string, string> */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), $this->moduleAttributes()['casts'] ?? []);
    }

    /** @return array{fillable?: list<string>, casts?: array<string, string>, defaults?: array<string, mixed>} */
    private function moduleAttributes(): array
    {
        if (!Container::getInstance()->bound('config')) {
            return [];
        }

        $extension = [];
        foreach ([...array_reverse(class_parents(static::class)), static::class] as $class) {
            $extension = array_replace_recursive($extension, config('modules.models.'.$class, []));
        }

        return $extension;
    }
}
