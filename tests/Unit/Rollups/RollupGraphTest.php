<?php

declare(strict_types=1);

use App\Support\Engine\RollupGraph;

it('reports a field that depends on itself as a cycle', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('x', 'x');

    expect($graph->detectCycle())->toBe(['x', 'x'])
        ->and($graph->topologicalOrder())->toBeNull();
});

it('reports two fields that depend on each other as a cycle naming both', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('a', 'b');
    $graph->addEdge('b', 'a');

    expect($graph->detectCycle())->toBe(['a', 'b', 'a']);
});

it('reports a cycle that only closes over a third field', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('a', 'b');
    $graph->addEdge('b', 'c');
    $graph->addEdge('c', 'a');

    expect($graph->detectCycle())->toBe(['a', 'b', 'c', 'a']);
});

it('leaves a branching but acyclic graph free of cycles', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('total', 'amount');
    $graph->addEdge('total', 'status');
    $graph->addEdge('report', 'total');

    expect($graph->detectCycle())->toBeNull();
});

it('orders every dependency before the field that reads it', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('report', 'total');
    $graph->addEdge('total', 'amount');

    expect($graph->topologicalOrder())->toBe(['amount', 'total', 'report']);
});

it('records a repeated dependency only once', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('total', 'amount');
    $graph->addEdge('total', 'amount');

    expect($graph->dependenciesOf('total'))->toBe(['amount']);
});

it('knows an isolated field and reports it without dependencies', function (): void {
    $graph = new RollupGraph;
    $graph->addNode('lonely');

    expect($graph->dependenciesOf('lonely'))->toBe([])
        ->and($graph->dependenciesOf('never-added'))->toBe([])
        ->and($graph->topologicalOrder())->toBe(['lonely']);
});

it('returns the order and the cycle from a single analysis', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('a', 'b');

    expect($graph->analyse())->toBe(['order' => ['b', 'a'], 'cycle' => null]);
});
