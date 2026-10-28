<?php

declare(strict_types=1);

use Illuminate\Support\Collection;

function abilityIs(string $ability, bool $expected): Closure
{
    return function (mixed $can) use ($ability, $expected): bool {
        $map = $can instanceof Collection ? $can->all() : (array) $can;

        return array_key_exists($ability, $map) && $map[$ability] === $expected;
    };
}

function medianMillis(callable $operation, int $runs = 5): float
{
    $operation();

    $samples = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}
