<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FieldReferenceNode;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\StringLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaOperator;
use App\Enums\Formulas\TokenType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Support\Formulas\FormulaParser;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->parser = app(FormulaParser::class);

    /** @var callable(string):FormulaNode */
    $this->parse = fn (string $formula): FormulaNode => $this->parser->parse($formula);

    /** @var callable(FormulaNode):string */
    $this->render = function (FormulaNode $node): string {
        if ($node instanceof NumberLiteralNode) {
            return $node->value;
        }

        if ($node instanceof StringLiteralNode) {
            return '"'.$node->value.'"';
        }

        if ($node instanceof BooleanLiteralNode) {
            return $node->value ? 'TRUE' : 'FALSE';
        }

        if ($node instanceof FieldReferenceNode) {
            return '{'.$node->key.'}';
        }

        if ($node instanceof UnaryOperationNode) {
            return '('.$node->operator->value.($this->render)($node->operand).')';
        }

        if ($node instanceof BinaryOperationNode) {
            return '('.($this->render)($node->left).' '.$node->operator->value.' '.($this->render)($node->right).')';
        }

        if ($node instanceof FunctionCallNode) {
            $arguments = array_map(
                fn (FormulaNode $argument): string => ($this->render)($argument),
                $node->arguments,
            );

            return $node->function->value.'('.implode('; ', $arguments).')';
        }

        $this->fail('The parser produced a node of unrenderable type '.$node::class.'.');
    };

    /** @var callable(FormulaNode):int */
    $this->positionOf = function (FormulaNode $node): int {
        return match (true) {
            $node instanceof NumberLiteralNode => $node->position,
            $node instanceof StringLiteralNode => $node->position,
            $node instanceof BooleanLiteralNode => $node->position,
            $node instanceof FieldReferenceNode => $node->position,
            $node instanceof UnaryOperationNode => $node->position,
            $node instanceof BinaryOperationNode => $node->position,
            $node instanceof FunctionCallNode => $node->position,
            default => $this->fail('The parser produced a node of unknown type '.$node::class.'.'),
        };
    };

    /** @var callable(FormulaNode):list<array{string, int}> */
    $this->flatten = function (FormulaNode $node): array {
        $entries = [[($this->render)($node), ($this->positionOf)($node)]];

        if ($node instanceof UnaryOperationNode) {
            $entries = array_merge($entries, ($this->flatten)($node->operand));
        }

        if ($node instanceof BinaryOperationNode) {
            $entries = array_merge(
                $entries,
                ($this->flatten)($node->left),
                ($this->flatten)($node->right),
            );
        }

        if ($node instanceof FunctionCallNode) {
            foreach ($node->arguments as $argument) {
                $entries = array_merge($entries, ($this->flatten)($argument));
            }
        }

        return $entries;
    };

    /** @var callable(string):FormulaSyntaxException */
    $this->syntaxError = function (string $formula): FormulaSyntaxException {
        try {
            ($this->parse)($formula);
        } catch (FormulaSyntaxException $exception) {
            return $exception;
        }

        $this->fail("Expected a syntax error for [{$formula}], but parsing succeeded.");
    };

    /** @var callable(FormulaSyntaxException):string */
    $this->readableReason = function (FormulaSyntaxException $exception): string {
        $message = trim($exception->getMessage());

        expect($message)->not->toBe('')
            ->and(preg_match_all('/\b\w{3,}\b/u', $message))->toBeGreaterThanOrEqual(3);

        return $message;
    };
});

test('the acceptance formula parses into the fully parenthesised tree', function (): void {
    $node = ($this->parse)('IF({amount} > 1000; ROUND({amount} * 0,93; 2); {amount})');

    expect(($this->render)($node))->toBe('IF(({amount} > 1000); ROUND(({amount} * 0.93); 2); {amount})');
});

test('operator precedence and associativity follow the binding table', function (string $formula, string $expected): void {
    expect(($this->render)(($this->parse)($formula)))->toBe($expected);
})->with([
    'multiplication binds tighter than addition' => ['1 + 2 * 3', '(1 + (2 * 3))'],
    'parentheses override the binding table' => ['(1 + 2) * 3', '((1 + 2) * 3)'],
    'multiplication binds tighter than subtraction' => ['1 - 2 * 3', '(1 - (2 * 3))'],
    'division binds tighter than addition' => ['1 + 8 / 4', '(1 + (8 / 4))'],
    'subtraction is left associative' => ['10 - 3 - 2', '((10 - 3) - 2)'],
    'division is left associative' => ['8 / 4 / 2', '((8 / 4) / 2)'],
    'addition binds tighter than a comparison' => ['1 + 2 < 3', '((1 + 2) < 3)'],
    'multiplication binds tighter than a comparison' => ['1 * 2 >= 3', '((1 * 2) >= 3)'],
    'comparison is left associative' => ['1 < 2 < 3', '((1 < 2) < 3)'],
    'a comparison binds tighter than equality' => ['{a} > 5 = TRUE', '(({a} > 5) = TRUE)'],
    'a less-or-equal comparison binds tighter than equality' => ['1 <= 2 = FALSE', '((1 <= 2) = FALSE)'],
    'a greater-or-equal comparison binds tighter than inequality' => ['1 >= 2 <> TRUE', '((1 >= 2) <> TRUE)'],
    'equality is left associative' => ['1 = 2 <> 3', '((1 = 2) <> 3)'],
    'addition binds tighter than equality' => ['1 + 2 = 3', '((1 + 2) = 3)'],
    'unary minus binds tighter than addition' => ['-2 + 3', '((-2) + 3)'],
    'unary minus on a field binds tighter than addition' => ['-{amount} + 5', '((-{amount}) + 5)'],
    'unary minus binds tighter than multiplication' => ['-2 * 3', '((-2) * 3)'],
    'unary minus is accepted on the right of an operator' => ['2 * -3', '(2 * (-3))'],
    'unary minus nests into itself' => ['--2', '(-(-2))'],
    'unary minus applies to a whole group' => ['-(1 + 2)', '(-(1 + 2))'],
    'a redundant group collapses to its inner expression' => ['(1)', '1'],
    'a bare field reference is a complete formula' => ['{amount}', '{amount}'],
    'a bare boolean is a complete formula' => ['TRUE', 'TRUE'],
    'a bare text literal is a complete formula' => ['"No. "', '"No. "'],
]);

test('a catalog function call carries its resolved enum case and its arguments', function (
    string $formula,
    string $functionValue,
    int $argumentCount,
    string $expectedRendering,
): void {
    $node = ($this->parse)($formula);

    expect($node)->toBeInstanceOf(FunctionCallNode::class);

    /** @var FunctionCallNode $node */
    expect($node->function)->toBe(FormulaFunction::from($functionValue))
        ->and($node->arguments)->toHaveCount($argumentCount)
        ->and(($this->render)($node))->toBe($expectedRendering);

    foreach ($node->arguments as $argument) {
        expect($argument)->toBeInstanceOf(FormulaNode::class);
    }
})->with([
    'three fixed arguments' => ['IF({a} > 1; "large"; "small")', 'IF', 3, 'IF(({a} > 1); "large"; "small")'],
    'variadic arguments' => ['SUM({a}; {b}; {c})', 'SUM', 3, 'SUM({a}; {b}; {c})'],
    'no arguments' => ['TODAY()', 'TODAY', 0, 'TODAY()'],
    'a text literal argument' => ['CONCAT("No. "; {record_number})', 'CONCAT', 2, 'CONCAT("No. "; {record_number})'],
    'a nested call as first argument' => ['ROUND(SUM({a}; {b}); 2)', 'ROUND', 2, 'ROUND(SUM({a}; {b}); 2)'],
    'three date arguments' => ['DATEDIF({a}; {b}; "d")', 'DATEDIF', 3, 'DATEDIF({a}; {b}; "d")'],
]);

test('a nested call becomes an argument node of its outer call', function (): void {
    $node = ($this->parse)('ROUND(SUM({a}; {b}); 2)');

    expect($node)->toBeInstanceOf(FunctionCallNode::class);

    /** @var FunctionCallNode $node */
    $inner = $node->arguments[0];

    expect($inner)->toBeInstanceOf(FunctionCallNode::class);

    /** @var FunctionCallNode $inner */
    expect($inner->function)->toBe(FormulaFunction::Sum)
        ->and($inner->arguments)->toHaveCount(2)
        ->and($node->arguments[1])->toBeInstanceOf(NumberLiteralNode::class);
});

test('a boolean literal materialises as a php boolean', function (string $formula, bool $expected): void {
    $node = ($this->parse)($formula);

    expect($node)->toBeInstanceOf(BooleanLiteralNode::class);

    /** @var BooleanLiteralNode $node */
    expect($node->value)->toBe($expected)
        ->and($node->position)->toBe(0);
})->with([
    'true' => ['TRUE', true],
    'false' => ['FALSE', false],
]);

test('a text literal keeps its decoded value verbatim', function (): void {
    $node = ($this->parse)('"No. "');

    expect($node)->toBeInstanceOf(StringLiteralNode::class);

    /** @var StringLiteralNode $node */
    expect($node->value)->toBe('No. ')
        ->and($node->position)->toBe(0);
});

test('a number literal keeps the normalised decimal string and never becomes a float', function (string $formula, string $expected): void {
    $node = ($this->parse)($formula);

    expect($node)->toBeInstanceOf(NumberLiteralNode::class);

    /** @var NumberLiteralNode $node */
    expect($node->value)->toBeString()
        ->and($node->value)->toBe($expected)
        ->and($node->position)->toBe(0);
})->with([
    'comma separator' => ['0,93', '0.93'],
    'period separator' => ['0.93', '0.93'],
    'integer' => ['1000', '1000'],
    'trailing zeros are preserved' => ['1,500', '1.500'],
]);

test('a field reference carries the bare key without its braces', function (): void {
    $node = ($this->parse)('{amount}');

    expect($node)->toBeInstanceOf(FieldReferenceNode::class);

    /** @var FieldReferenceNode $node */
    expect($node->key)->toBe('amount')
        ->and($node->position)->toBe(0);
});

test('every node carries the position of the first token of its own expression', function (string $formula, array $expected): void {
    expect(($this->flatten)(($this->parse)($formula)))->toBe($expected);
})->with([
    'the acceptance formula' => [
        'IF({amount} > 1000; ROUND({amount} * 0,93; 2); {amount})',
        [
            ['IF(({amount} > 1000); ROUND(({amount} * 0.93); 2); {amount})', 0],
            ['({amount} > 1000)', 3],
            ['{amount}', 3],
            ['1000', 14],
            ['ROUND(({amount} * 0.93); 2)', 20],
            ['({amount} * 0.93)', 26],
            ['{amount}', 26],
            ['0.93', 37],
            ['2', 43],
            ['{amount}', 47],
        ],
    ],
    'a unary operation and every literal kind' => [
        '-2 + IF(TRUE; "a"; "b")',
        [
            ['((-2) + IF(TRUE; "a"; "b"))', 0],
            ['(-2)', 0],
            ['2', 1],
            ['IF(TRUE; "a"; "b")', 5],
            ['TRUE', 8],
            ['"a"', 14],
            ['"b"', 19],
        ],
    ],
    'a grouped left operand' => [
        '(1 + 2) * 3',
        [
            ['((1 + 2) * 3)', 0],
            ['(1 + 2)', 1],
            ['1', 1],
            ['2', 5],
            ['3', 10],
        ],
    ],
    'a nested right operand' => [
        '1 + 2 * 3',
        [
            ['(1 + (2 * 3))', 0],
            ['1', 0],
            ['(2 * 3)', 4],
            ['2', 4],
            ['3', 8],
        ],
    ],
]);

test('the operator enum carries exactly the eleven operators of the binding table', function (): void {
    $names = array_map(static fn (FormulaOperator $operator): string => $operator->name, FormulaOperator::cases());
    $values = array_map(static fn (FormulaOperator $operator): string => $operator->value, FormulaOperator::cases());

    expect($names)->toHaveCount(11)
        ->and($names)->toEqualCanonicalizing([
            'Equal',
            'NotEqual',
            'LessThan',
            'LessThanOrEqual',
            'GreaterThan',
            'GreaterThanOrEqual',
            'Ampersand',
            'Plus',
            'Minus',
            'Asterisk',
            'Slash',
        ])
        ->and($values)->toEqualCanonicalizing(['=', '<>', '<', '<=', '>', '>=', '&', '+', '-', '*', '/'])
        ->and(array_unique($values))->toHaveCount(11);
});

test('the operator enum is the single place that carries the binding strengths', function (string $value, int $precedence, ?int $prefixPrecedence): void {
    $operator = FormulaOperator::from($value);

    expect($operator->precedence())->toBe($precedence)
        ->and($operator->prefixPrecedence())->toBe($prefixPrecedence)
        ->and($operator->isLeftAssociative())->toBeTrue();
})->with([
    'equal' => ['=', 1, null],
    'not equal' => ['<>', 1, null],
    'less than' => ['<', 2, null],
    'less than or equal' => ['<=', 2, null],
    'greater than' => ['>', 2, null],
    'greater than or equal' => ['>=', 2, null],
    'ampersand' => ['&', 3, null],
    'plus' => ['+', 4, null],
    'minus' => ['-', 4, 6],
    'asterisk' => ['*', 5, null],
    'slash' => ['/', 5, null],
]);

test('an operator token type maps to its operator and every other token type maps to null', function (): void {
    $mapped = [];

    foreach (TokenType::cases() as $type) {
        $operator = FormulaOperator::fromTokenType($type);
        $mapped[$type->name] = $operator?->value;
    }

    expect($mapped)->toBe([
        'Number' => null,
        'Text' => null,
        'Boolean' => null,
        'FieldReference' => null,
        'Identifier' => null,
        'Plus' => '+',
        'Minus' => '-',
        'Asterisk' => '*',
        'Slash' => '/',
        'Ampersand' => '&',
        'Equal' => '=',
        'NotEqual' => '<>',
        'GreaterThan' => '>',
        'GreaterThanOrEqual' => '>=',
        'LessThan' => '<',
        'LessThanOrEqual' => '<=',
        'LeftParenthesis' => null,
        'RightParenthesis' => null,
        'Semicolon' => null,
        'EndOfInput' => null,
    ]);
});

test('a text literal that spells an operator stays a text literal', function (): void {
    $node = ($this->parse)('"+"');

    expect($node)->toBeInstanceOf(StringLiteralNode::class);

    /** @var StringLiteralNode $node */
    expect($node->value)->toBe('+')
        ->and(($this->render)(($this->parse)('CONCAT("+"; "-")')))->toBe('CONCAT("+"; "-")');
});

test('a division by zero parses successfully instead of being evaluated', function (): void {
    $node = ($this->parse)('1 / 0');

    expect($node)->toBeInstanceOf(BinaryOperationNode::class);

    /** @var BinaryOperationNode $node */
    expect($node->operator)->toBe(FormulaOperator::Slash)
        ->and($node->left)->toBeInstanceOf(NumberLiteralNode::class)
        ->and($node->right)->toBeInstanceOf(NumberLiteralNode::class)
        ->and(($this->render)($node))->toBe('(1 / 0)');
});

test('a constant subexpression is never folded into a literal', function (string $formula, string $expected): void {
    $node = ($this->parse)($formula);

    expect($node)->toBeInstanceOf(BinaryOperationNode::class)
        ->and($node)->not->toBeInstanceOf(NumberLiteralNode::class)
        ->and(($this->render)($node))->toBe($expected);
})->with([
    'addition' => ['1 + 1', '(1 + 1)'],
    'multiplication' => ['2 * 3', '(2 * 3)'],
    'comparison' => ['1 = 1', '(1 = 1)'],
]);

test('a mixed-type operand pair parses because type checking is not this slice', function (): void {
    $node = ($this->parse)('1 + "2"');

    expect($node)->toBeInstanceOf(BinaryOperationNode::class);

    /** @var BinaryOperationNode $node */
    expect($node->operator)->toBe(FormulaOperator::Plus)
        ->and($node->left)->toBeInstanceOf(NumberLiteralNode::class)
        ->and($node->right)->toBeInstanceOf(StringLiteralNode::class);
});

test('a structurally invalid formula is rejected with a positioned cause', function (string $formula, int $expectedPosition, array $expectedFragments): void {
    $exception = ($this->syntaxError)($formula);
    $message = ($this->readableReason)($exception);

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->position)->toBe($expectedPosition);

    foreach ($expectedFragments as $fragment) {
        expect($message)->toContain($fragment);
    }
})->with([
    'a missing right operand' => ['1 +', 3, ['obwohl noch ein Operand erwartet wird']],
    'an unclosed parenthesis' => ['(1 + 2', 0, ['Die an Stelle 0 geöffnete Klammer wird nie geschlossen']],
    'a surplus closing parenthesis' => ['1 + 2)', 5, [')']],
    'two expressions without an operator' => ['{a} {b}', 4, ['Der Ausdruck "b" darf an Stelle 4 nicht stehen']],
    'a lone argument separator' => [';', 0, [';']],
    'an empty formula' => ['', 0, ['ohne einen einzigen Ausdruck zu enthalten']],
    'a whitespace only formula' => ['  ', 2, ['ohne einen einzigen Ausdruck zu enthalten']],
    'an unknown function name' => ['FOOBAR(1)', 0, ['FOOBAR']],
    'too few arguments for a fixed arity function' => ['IF({a}; 1)', 0, ['IF']],
    'an argument passed to a nullary function' => ['TODAY(1)', 0, ['TODAY']],
    'no argument passed to a variadic function' => ['SUM()', 0, ['SUM']],
    'a catalog name without a call' => ['TODAY', 0, ['TODAY']],
    'a trailing argument separator' => ['SUM(1;)', 6, [')']],
    'a text literal used in operator position' => ['1 "+" 2', 2, ['+']],
]);

test('two hundred nested parentheses are rejected at the configured maximum depth', function (): void {
    $maximum = (int) config('formulas.max_nesting_depth');

    $exception = ($this->syntaxError)(str_repeat('(', 200));
    $message = ($this->readableReason)($exception);

    expect($maximum)->toBe(32)
        ->and($exception->position)->toBe($maximum)
        ->and($message)->toContain((string) $maximum);
});

test('the configured nesting depth is the one that is enforced', function (): void {
    config()->set('formulas.max_nesting_depth', 5);

    $accepted = ($this->parse)(str_repeat('(', 4).'1'.str_repeat(')', 4));
    $exception = ($this->syntaxError)(str_repeat('(', 5).'1'.str_repeat(')', 5));
    $message = ($this->readableReason)($exception);

    expect(($this->render)($accepted))->toBe('1')
        ->and($exception->position)->toBe(5)
        ->and($message)->toContain('5');
});

test('two hundred nested function calls hit the same depth guard', function (): void {
    $maximum = (int) config('formulas.max_nesting_depth');

    $exception = ($this->syntaxError)(str_repeat('SUM(', 200).'1'.str_repeat(')', 200));
    $message = ($this->readableReason)($exception);

    expect($exception->position)->toBe($maximum * 4)
        ->and($message)->toContain((string) $maximum);
});

test('two hundred chained unary minus operators hit the same depth guard', function (): void {
    $maximum = (int) config('formulas.max_nesting_depth');

    $exception = ($this->syntaxError)(str_repeat('-', 200).'1');
    $message = ($this->readableReason)($exception);

    expect($exception->position)->toBe($maximum)
        ->and($message)->toContain((string) $maximum);
});

test('a lexer error propagates through the parser unchanged', function (): void {
    $exception = ($this->syntaxError)('1 + {');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(4)
        ->and($exception->getMessage())->toContain('nie mit einer schließenden geschweiften Klammer beendet');
});

test('an oversized formula is still rejected by the lexer length guard', function (): void {
    $maximum = (int) config('formulas.max_formula_length');

    $exception = ($this->syntaxError)(str_repeat('1', $maximum + 1));

    expect($exception->position)->toBe(0)
        ->and($exception->getMessage())->toContain((string) $maximum);
});

test('the same parser instance parses correctly again after a nesting failure', function (): void {
    $parser = $this->parser;

    expect(fn (): FormulaNode => $parser->parse(str_repeat('(', 200)))
        ->toThrow(FormulaSyntaxException::class);

    expect(($this->render)($parser->parse('1 + 2 * 3')))->toBe('(1 + (2 * 3))')
        ->and(($this->render)($parser->parse('-2 + 3')))->toBe('((-2) + 3)');
});

test('the parser source contains no dynamic code execution', function (): void {
    $path = app_path('Support/Formulas/FormulaParser.php');

    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)->not->toContain('eval')
        ->and($source)->not->toContain('create_function')
        ->and($source)->not->toContain('assert(')
        ->and($source)->not->toContain('PhpOffice');
});
