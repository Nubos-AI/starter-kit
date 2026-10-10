<?php

declare(strict_types=1);

namespace App\Support\Engine;

class FilterFieldKeyCollector
{
    /**
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    public function collect(array $tree): array
    {
        /** @var list<string> $keys */
        $keys = [];

        $this->walk($tree, $keys);

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $keys
     */
    private function walk(mixed $node, array &$keys): void
    {
        if (!is_array($node)) {
            return;
        }

        if (array_key_exists('conditions', $node)) {
            if (!is_array($node['conditions'])) {
                return;
            }

            foreach ($node['conditions'] as $child) {
                $this->walk($child, $keys);
            }

            return;
        }

        $field = $node['field'] ?? null;

        if (is_string($field) && $field !== '') {
            $keys[] = $field;
        }
    }
}
