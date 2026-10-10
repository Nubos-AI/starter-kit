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

test('a formula yields every field key it references', function (string $formula, array $expected): void {
    expect(($this->collect)($formula))->toBe($expected);
})->with([
    'two operands' => ['{a} + {b}', ['a', 'b']],
    'a bare field reference' => ['{a}', ['a']],
    'nested function arguments' => ['IF({a} > 0; SUM({b}; ROUND({c}; 2)); 0)', ['a', 'b', 'c']],
]);

test('a key referenced twice is collected exactly once', function (): void {
    $keys = ($this->collect)('{a} + {a}');

    expect($keys)->toBe(['a'])
        ->and($keys)->toHaveCount(1);
});

test('the collected keys come back sorted as strings', function (string $formula, array $expected): void {
    expect(($this->collect)($formula))->toBe($expected);
})->with([
    'reversed operands' => ['{b} + {a}', ['a', 'b']],
    'digits are compared as text' => ['{b10} + {b2}', ['b10', 'b2']],
    'unordered function arguments' => ['SUM({c}; {a}; {b})', ['a', 'b', 'c']],
]);

test('a formula without any field reference yields an empty list', function (): void {
    $keys = ($this->collect)('TODAY()');

    expect($keys)->toBe([])
        ->and($keys)->not->toBeNull()
        ->and(array_is_list($keys))->toBeTrue();
});

test('a field key below a unary operator is not lost', function (string $formula, array $expected): void {
    expect(($this->collect)($formula))->toBe($expected);
})->with([
    'a negated field' => ['-{a}', ['a']],
    'two negated operands' => ['-{a} * -{b}', ['a', 'b']],
    'a doubly negated field' => ['--{a}', ['a']],
    'a negated function argument' => ['SUM(-{a}; {b})', ['a', 'b']],
    'a negated group' => ['-({a} + {b})', ['a', 'b']],
]);

test('literals contribute nothing to the collected keys', function (string $formula, array $expected): void {
    expect(($this->collect)($formula))->toBe($expected);
})->with([
    'a boolean and a text branch' => ['IF(TRUE; "x"; {a})', ['a']],
    'only numbers' => ['1 + 2', []],
    'a text argument beside a field' => ['CONCAT("No. "; {record_number})', ['record_number']],
]);

test('a date difference call yields both of its date arguments', function (): void {
    expect(($this->collect)('DATEDIF({start}; {end}; "days")'))->toBe(['end', 'start']);
});

test('the collector is independent of type checking and of cycle rejection', function (string $formula, array $expected): void {
    expect(($this->collect)($formula))->toBe($expected);
})->with([
    'a type conflict still yields its key' => ['{text_field} * 2', ['text_field']],
    'a self reference still yields its key' => ['{f} + 1', ['f']],
    'a type conflict without any field' => ['ROUND("abc"; 2)', []],
]);

test('the collected keys form a zero indexed list even after deduplication', function (): void {
    $keys = ($this->collect)('SUM({b}; {a}; {b})');

    expect($keys)->toBe(['a', 'b'])
        ->and(array_is_list($keys))->toBeTrue()
        ->and(array_keys($keys))->toBe([0, 1]);
});

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

test('an edge is written from the owning field to its first reference', function (): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', '{a} + {b}');
    ($this->addEdges)($graph, 'a', '{f}');

    $cycle = $graph->detectCycle();

    expect($cycle)->not->toBeNull()
        ->and($cycle)->toContain('f')
        ->and($cycle)->toContain('a');
});

test('an edge is written from the owning field to its second reference', function (): void {
    $graph = new RollupGraph;

    ($this->addEdges)($graph, 'f', '{a} + {b}');
    ($this->addEdges)($graph, 'b', '{f}');

    $cycle = $graph->detectCycle();

    expect($cycle)->not->toBeNull()
        ->and($cycle)->toContain('f')
        ->and($cycle)->toContain('b');
});

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
