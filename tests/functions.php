<?php

declare(strict_types=1);

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
