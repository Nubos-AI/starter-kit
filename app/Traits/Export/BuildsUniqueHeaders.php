<?php

declare(strict_types=1);

namespace App\Traits\Export;

trait BuildsUniqueHeaders
{
    /**
     * @param  array<string, true>  $taken
     */
    private function uniqueHeader(string $label, array &$taken): string
    {
        $candidate = $label;
        $suffix = 1;

        while (isset($taken[$candidate])) {
            $suffix++;
            $candidate = "{$label} ({$suffix})";
        }

        $taken[$candidate] = true;

        return $candidate;
    }
}
