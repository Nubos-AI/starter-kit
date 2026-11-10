<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaNode;
use App\Support\Engine\RollupGraph;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaReferenceCollector;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->parser = app(FormulaParser::class);
    $this->collector = new FormulaReferenceCollector;

    /** @var callable(string):FormulaNode */
    $this->parse = fn (string $formula): FormulaNode => $this->parser->parse($formula);

    /** @var callable(string):list<string> */
    $this->collect = fn (string $formula): array => $this->collector->collect(($this->parse)($formula));

    /** @var callable(RollupGraph,string,string):void */
    $this->addEdges = function (RollupGraph $graph, string $fieldKey, string $formula): void {
        $this->collector->addDependencyEdges($graph, $fieldKey, ($this->parse)($formula));
    };

    /** @var callable(callable):InvalidArgumentException */
    $this->rejectionOf = function (callable $call): InvalidArgumentException {
        try {
            $call();
        } catch (InvalidArgumentException $exception) {
            return $exception;
        }

        $this->fail('Expected the collector to reject an unsupported node type, but the call succeeded.');
    };

    /** @var callable():FormulaNode */
    $this->foreignNode = fn (): FormulaNode => new class implements FormulaNode {};
});

test('a formula yields every field key it references once, sorted as strings, as a zero indexed list', function (string $formula, array $expected): void {
    $keys = ($this->collect)($formula);

    expect($keys)->toBe($expected)
        ->and(array_is_list($keys))->toBeTrue();
})->with([
    'two operands' => ['{a} + {b}', ['a', 'b']],
    'a bare field reference' => ['{a}', ['a']],
    'nested function arguments' => ['IF({a} > 0; SUM({b}; ROUND({c}; 2)); 0)', ['a', 'b', 'c']],
    'a key referenced twice' => ['{a} + {a}', ['a']],
    'reversed operands come back sorted' => ['{b} + {a}', ['a', 'b']],
    'digits are compared as text' => ['{b10} + {b2}', ['b10', 'b2']],
    'unordered function arguments come back sorted' => ['SUM({c}; {a}; {b})', ['a', 'b', 'c']],
    'a formula without any field reference' => ['TODAY()', []],
    'a negated field' => ['-{a}', ['a']],
    'two negated operands' => ['-{a} * -{b}', ['a', 'b']],
    'a doubly negated field' => ['--{a}', ['a']],
    'a negated function argument' => ['SUM(-{a}; {b})', ['a', 'b']],
    'a negated group' => ['-({a} + {b})', ['a', 'b']],
    'a boolean and a text branch contribute nothing' => ['IF(TRUE; "x"; {a})', ['a']],
    'only numbers contribute nothing' => ['1 + 2', []],
    'a text argument beside a field contributes nothing' => ['CONCAT("No. "; {record_number})', ['record_number']],
    'a date difference call yields both of its date arguments' => ['DATEDIF({start}; {end}; "days")', ['end', 'start']],
    'a type conflict still yields its key' => ['{text_field} * 2', ['text_field']],
    'a self reference still yields its key' => ['{f} + 1', ['f']],
    'a type conflict without any field' => ['ROUND("abc"; 2)', []],
    'a deduplicated list stays zero indexed' => ['SUM({b}; {a}; {b})', ['a', 'b']],
]);

test('the collector keeps no state between two calls', function (): void {
    $first = ($this->collect)('{a} + {b}');
    $second = ($this->collect)('{c}');
    $third = ($this->collect)('{a} + {b}');

    expect($first)->toBe(['a', 'b'])
        ->and($second)->toBe(['c'])
        ->and($third)->toBe(['a', 'b']);
});

test('an unsupported node type is rejected when collecting', function (): void {
    $node = ($this->foreignNode)();
    $collector = $this->collector;

    $exception = ($this->rejectionOf)(function () use ($collector, $node): void {
        $collector->collect($node);
    });

    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toContain($node::class)
        ->and(preg_match('/[^\x20-\x7E]/', str_replace($node::class, '', $exception->getMessage())))->toBe(0);
});

test('an unsupported node type is rejected when adding dependency edges', function (): void {
    $node = ($this->foreignNode)();
    $collector = $this->collector;
    $graph = new RollupGraph;

    $exception = ($this->rejectionOf)(function () use ($collector, $graph, $node): void {
        $collector->addDependencyEdges($graph, 'f', $node);
    });

    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toContain($node::class);
});

test('an edge is written from the owning field to each of its references', function (string $reference): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', '{a} + {b}');
    ($this->addEdges)($graph, $reference, '{f}');

    $cycle = $graph->detectCycle();

    expect($cycle)->not->toBeNull()
        ->and($cycle)->toContain('f')
        ->and($cycle)->toContain($reference);
})->with([
    'first' => ['a'],
    'second' => ['b'],
]);

test('dependency edges alone leave the graph acyclic', function (): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', '{a} + {b}');

    expect($graph->detectCycle())->toBeNull();
});

test('a formula referencing its own field produces a trivial cycle', function (): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', '{f} + 1');

    $cycle = $graph->detectCycle();

    expect($cycle)->not->toBeNull()
        ->and($cycle)->toContain('f');
});

test('a formula without references adds no edge and keeps the graph acyclic', function (): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', 'TODAY()');

    expect($graph->detectCycle())->toBeNull();
});

test('an existing graph is extended and its earlier edges keep working', function (): void {
    $graph = new RollupGraph;
    $graph->addEdge('x', 'y');

    ($this->addEdges)($graph, 'f', '{a} + {b}');
    ($this->addEdges)($graph, 'y', '{x}');

    $cycle = $graph->detectCycle();

    expect($cycle)->not->toBeNull()
        ->and($cycle)->toContain('x')
        ->and($cycle)->toContain('y');
});
