<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FieldReferenceNode;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\StringLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Support\Engine\RollupGraph;
use InvalidArgumentException;

class FormulaReferenceCollector
{
    /**
     * @return list<string>
     */
    public function collect(FormulaNode $node): array
    {
        /** @var array<string, true> $seen */
        $seen = [];

        $this->collectInto($node, $seen);

        $keys = array_keys($seen);
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function addDependencyEdges(RollupGraph $graph, string $fieldKey, FormulaNode $node): void
    {
        $graph->addNode($fieldKey);

        foreach ($this->collect($node) as $key) {
            $graph->addEdge($fieldKey, $key);
        }
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectInto(FormulaNode $node, array &$seen): void
    {
        match (true) {
            $node instanceof NumberLiteralNode,
            $node instanceof StringLiteralNode,
            $node instanceof BooleanLiteralNode => null,
            $node instanceof FieldReferenceNode => $seen[$node->key] = true,
            $node instanceof UnaryOperationNode => $this->collectInto($node->operand, $seen),
            $node instanceof BinaryOperationNode => $this->collectBinaryInto($node, $seen),
            $node instanceof FunctionCallNode => $this->collectArgumentsInto($node, $seen),
            default => throw new InvalidArgumentException('Unsupported formula node type '.$node::class.'.'),
        };
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectBinaryInto(BinaryOperationNode $node, array &$seen): void
    {
        $this->collectInto($node->left, $seen);
        $this->collectInto($node->right, $seen);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectArgumentsInto(FunctionCallNode $node, array &$seen): void
    {
        foreach ($node->arguments as $argument) {
            $this->collectInto($argument, $seen);
        }
    }
}
