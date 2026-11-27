<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Generator;
use IteratorAggregate;

/**
 * @template TComponent of object
 *
 * @implements IteratorAggregate<int, TComponent>
 */
class ActiveModuleComponents implements IteratorAggregate
{
    /**
     * @param  class-string<TComponent>  $contract
     * @param  iterable<mixed>  $components
     */
    public function __construct(
        private readonly RegisteredModuleComponents $registered,
        private readonly string $contract,
        private readonly iterable $components,
    ) {}

    /**
     * @return Generator<int, TComponent>
     */
    public function getIterator(): Generator
    {
        $typed = [];

        foreach ($this->components as $component) {
            if ($component instanceof $this->contract) {
                $typed[] = $component;
            }
        }

        yield from $this->registered->only($typed);
    }
}
