<?php

declare(strict_types=1);

use App\Support\Engine\RollupGraph;

it('reports a closed dependency chain as a cycle naming every field on it', function (array $edges, array $expectedCycle): void {
    $graph = new RollupGraph;

    foreach ($edges as [$from, $to]) {
        $graph->addEdge($from, $to);
    }

    expect($graph->detectCycle())->toBe($expectedCycle)
        ->and($graph->topologicalOrder())->toBeNull();
})->with([
    'a field that depends on itself' => [[['x', 'x']], ['x', 'x']],
    'two fields that depend on each other' => [[['a', 'b'], ['b', 'a']], ['a', 'b', 'a']],
    'a cycle that only closes over a third field' => [[['a', 'b'], ['b', 'c'], ['c', 'a']], ['a', 'b', 'c', 'a']],
]);

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
