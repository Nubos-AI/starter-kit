<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\TraversalColor;

class RollupGraph
{
    /**
     * @var array<string, list<string>>
     */
    private array $adjacency = [];

    public function addNode(string $node): void
    {
        $this->adjacency[$node] ??= [];
    }

    public function addEdge(string $from, string $to): void
    {
        $this->addNode($from);
        $this->addNode($to);

        if (!in_array($to, $this->adjacency[$from], true)) {
            $this->adjacency[$from][] = $to;
        }
    }

    /**
     * @return list<string>
     */
    public function dependenciesOf(string $node): array
    {
        return $this->adjacency[$node] ?? [];
    }

    /**
     * @return array{order: list<string>|null, cycle: list<string>|null}
     */
    public function analyse(): array
    {
        /** @var array<string, TraversalColor> $colour */
        $colour = array_fill_keys(array_keys($this->adjacency), TraversalColor::White);

        /** @var list<string> $order */
        $order = [];

        foreach (array_keys($this->adjacency) as $node) {
            if ($colour[$node] !== TraversalColor::White) {
                continue;
            }

            /** @var list<string> $stack */
            $stack = [];
            $cycle = $this->visit($node, $colour, $stack, $order);

            if ($cycle !== null) {
                return ['order' => null, 'cycle' => $cycle];
            }
        }

        return ['order' => $order, 'cycle' => null];
    }

    /**
     * @return list<string>|null
     */
    public function detectCycle(): ?array
    {
        return $this->analyse()['cycle'];
    }

    /**
     * @return list<string>|null
     */
    public function topologicalOrder(): ?array
    {
        return $this->analyse()['order'];
    }

    /**
     * @param  array<string, TraversalColor>  $colour
     * @param  list<string>  $stack
     * @param  list<string>  $order
     * @return list<string>|null
     */
    private function visit(string $node, array &$colour, array &$stack, array &$order): ?array
    {
        $colour[$node] = TraversalColor::Gray;
        $stack[] = $node;

        foreach ($this->adjacency[$node] as $next) {
            $colour[$next] ??= TraversalColor::White;

            if ($colour[$next] === TraversalColor::Gray) {
                $start = array_search($next, $stack, true);
                $path = array_slice($stack, $start === false ? 0 : $start);
                $path[] = $next;

                return $path;
            }

            if ($colour[$next] === TraversalColor::White) {
                $cycle = $this->visit($next, $colour, $stack, $order);

                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        array_pop($stack);
        $colour[$node] = TraversalColor::Black;
        $order[] = $node;

        return null;
    }
}
