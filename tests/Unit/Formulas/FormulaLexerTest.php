<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaFunctionHandler;
use App\DTOs\Formulas\FormulaToken;
use App\Enums\Formulas\TokenType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Support\Formulas\FormulaLexer;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):list<FormulaToken> */
    $this->tokenize = fn (string $formula): array => (new FormulaLexer)->tokenize($formula);

    /** @var callable(string):list<array{TokenType, string, int}> */
    $this->triples = function (string $formula): array {
        return array_map(
            fn (FormulaToken $token): array => [$token->type, $token->text, $token->position],
            ($this->tokenize)($formula),
        );
    };

    /** @var callable(string):FormulaSyntaxException */
    $this->syntaxError = function (string $formula): FormulaSyntaxException {
        try {
            ($this->tokenize)($formula);
        } catch (FormulaSyntaxException $exception) {
            return $exception;
        }

        $this->fail("Expected a syntax error for [{$formula}], but tokenizing succeeded.");
    };
});

test('the acceptance formula tokenizes into the complete expected sequence', function (): void {
    $formula = 'IF({amount} > 1000; ROUND({amount} * 0,93; 2); {amount})';

    $tokens = ($this->tokenize)($formula);
    $last = $tokens[count($tokens) - 1];

    $sequence = array_map(
        fn (FormulaToken $token): array => [$token->type, $token->text, $token->position],
        array_slice($tokens, 0, -1),
    );

    expect($sequence)->toBe([
        [TokenType::Identifier, 'IF', 0],
        [TokenType::LeftParenthesis, '(', 2],
        [TokenType::FieldReference, 'amount', 3],
        [TokenType::GreaterThan, '>', 12],
        [TokenType::Number, '1000', 14],
        [TokenType::Semicolon, ';', 18],
        [TokenType::Identifier, 'ROUND', 20],
        [TokenType::LeftParenthesis, '(', 25],
        [TokenType::FieldReference, 'amount', 26],
        [TokenType::Asterisk, '*', 35],
        [TokenType::Number, '0.93', 37],
        [TokenType::Semicolon, ';', 41],
        [TokenType::Number, '2', 43],
        [TokenType::RightParenthesis, ')', 44],
        [TokenType::Semicolon, ';', 45],
        [TokenType::FieldReference, 'amount', 47],
        [TokenType::RightParenthesis, ')', 55],
    ])
        ->and($last->type)->toBe(TokenType::EndOfInput)
        ->and($last->position)->toBe(56);
});

test('every operator and punctuation character lexes as exactly one token', function (string $formula, string $expectedType): void {
    $tokens = ($this->tokenize)($formula);

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0]->type->name)->toBe($expectedType)
        ->and($tokens[0]->text)->toBe($formula)
        ->and($tokens[0]->position)->toBe(0)
        ->and($tokens[1]->type->name)->toBe('EndOfInput');
})->with([
    'plus' => ['+', 'Plus'],
    'minus' => ['-', 'Minus'],
    'asterisk' => ['*', 'Asterisk'],
    'slash' => ['/', 'Slash'],
    'equal' => ['=', 'Equal'],
    'not equal' => ['<>', 'NotEqual'],
    'less than' => ['<', 'LessThan'],
    'less than or equal' => ['<=', 'LessThanOrEqual'],
    'greater than' => ['>', 'GreaterThan'],
    'greater than or equal' => ['>=', 'GreaterThanOrEqual'],
    'left parenthesis' => ['(', 'LeftParenthesis'],
    'right parenthesis' => [')', 'RightParenthesis'],
    'semicolon' => [';', 'Semicolon'],
]);

test('an uppercase boolean word lexes as a boolean token', function (string $formula): void {
    $tokens = ($this->tokenize)($formula);

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0]->type)->toBe(TokenType::Boolean)
        ->and($tokens[0]->text)->toBe($formula)
        ->and($tokens[0]->position)->toBe(0);
})->with(['TRUE', 'FALSE']);

test('a boolean word that is not strictly uppercase lexes as an identifier', function (string $formula): void {
    $tokens = ($this->tokenize)($formula);

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0]->type)->toBe(TokenType::Identifier)
        ->and($tokens[0]->text)->toBe($formula)
        ->and($tokens[0]->position)->toBe(0);
})->with(['true', 'True', 'false']);

test('an unknown function name lexes as an identifier followed by its call punctuation', function (): void {
    expect(($this->triples)('FOOBAR(1)'))->toBe([
        [TokenType::Identifier, 'FOOBAR', 0],
        [TokenType::LeftParenthesis, '(', 6],
        [TokenType::Number, '1', 7],
        [TokenType::RightParenthesis, ')', 8],
        [TokenType::EndOfInput, '', 9],
    ]);
});

test('a field reference carries the bare key without its braces', function (): void {
    $tokens = ($this->tokenize)('{amount}');

    expect($tokens[0]->type)->toBe(TokenType::FieldReference)
        ->and($tokens[0]->text)->toBe('amount')
        ->and($tokens[0]->position)->toBe(0);
});

test('a text literal carries the decoded value without its quotes', function (): void {
    $tokens = ($this->tokenize)('"a\"b"');

    expect($tokens[0]->type)->toBe(TokenType::Text)
        ->and($tokens[0]->text)->toBe('a"b')
        ->and($tokens[0]->position)->toBe(0);
});

test('a backslash before any character other than a quote stays raw text', function (): void {
    $tokens = ($this->tokenize)('"a\b"');

    expect($tokens[0]->type)->toBe(TokenType::Text)
        ->and($tokens[0]->text)->toBe('a\b')
        ->and($tokens[0]->position)->toBe(0);
});

test('whitespace produces no token and does not shift the positions that follow', function (): void {
    expect(($this->triples)('  1  +  2 '))->toBe([
        [TokenType::Number, '1', 2],
        [TokenType::Plus, '+', 5],
        [TokenType::Number, '2', 8],
        [TokenType::EndOfInput, '', 10],
    ]);
});

test('a comma and a period as decimal separator produce the same number token', function (): void {
    $comma = ($this->tokenize)('1,5');
    $period = ($this->tokenize)('1.5');

    expect($comma[0]->type)->toBe(TokenType::Number)
        ->and($comma[0]->text)->toBe('1.5')
        ->and($period[0]->type)->toBe(TokenType::Number)
        ->and($period[0]->text)->toBe('1.5');
});

test('an empty formula produces exactly one end-of-input token', function (): void {
    $tokens = ($this->tokenize)('');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->type)->toBe(TokenType::EndOfInput)
        ->and($tokens[0]->position)->toBe(0);
});

test('the end-of-input token sits at the character length of the formula', function (string $formula, int $expectedPosition): void {
    $tokens = ($this->tokenize)($formula);
    $last = $tokens[count($tokens) - 1];

    expect($last->type)->toBe(TokenType::EndOfInput)
        ->and($last->position)->toBe($expectedPosition)
        ->and($expectedPosition)->toBe(mb_strlen($formula));
})->with([
    'acceptance formula' => ['IF({amount} > 1000; ROUND({amount} * 0,93; 2); {amount})', 56],
    'multibyte text' => ['"€" + 1', 7],
    'plain arithmetic' => ['1 + 2', 5],
]);

test('positions count characters and not bytes', function (): void {
    $tokens = ($this->tokenize)('"€" + 1');

    expect($tokens[0]->type)->toBe(TokenType::Text)
        ->and($tokens[0]->text)->toBe('€')
        ->and($tokens[0]->position)->toBe(0)
        ->and($tokens[1]->type)->toBe(TokenType::Plus)
        ->and($tokens[1]->position)->toBe(4)
        ->and($tokens[2]->type)->toBe(TokenType::Number)
        ->and($tokens[2]->position)->toBe(6);
});

test('a formula of exactly the maximum length is accepted', function (): void {
    $max = (int) config('formulas.max_formula_length');
    $formula = str_repeat('1', $max);

    $tokens = ($this->tokenize)($formula);

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0]->type)->toBe(TokenType::Number)
        ->and(mb_strlen($tokens[0]->text))->toBe($max)
        ->and($tokens[1]->type)->toBe(TokenType::EndOfInput)
        ->and($tokens[1]->position)->toBe($max);
});

test('a doubled opening brace is rejected at the first brace', function (): void {
    $exception = ($this->syntaxError)('{{amount}}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a doubled opening brace inside a formula is rejected at its own position', function (): void {
    $exception = ($this->syntaxError)('1 + {{amount}}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(4)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('an unterminated field reference is rejected at the opening brace', function (): void {
    $exception = ($this->syntaxError)('{amount');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('an empty field reference is rejected', function (): void {
    $exception = ($this->syntaxError)('{}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a field key containing a space is rejected', function (): void {
    $exception = ($this->syntaxError)('{Amount Net}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a field key starting with a digit is rejected', function (): void {
    $exception = ($this->syntaxError)('{1a}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a stray closing brace after a field reference is rejected at that brace', function (): void {
    $exception = ($this->syntaxError)('{amount}}');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(8)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('an unterminated text literal is rejected at the opening quote', function (): void {
    $exception = ($this->syntaxError)('"unterminated');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('an escaped closing quote does not terminate a text literal', function (): void {
    $exception = ($this->syntaxError)('"abc\"');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('two consecutive decimal separators are rejected at the second one', function (): void {
    $exception = ($this->syntaxError)('1..5');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(2)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a comma followed later by a period in one number is rejected', function (): void {
    $exception = ($this->syntaxError)('1,5.5');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(3)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a period followed later by a comma in one number is rejected', function (): void {
    $exception = ($this->syntaxError)('1.5,5');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(3)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a number ending in a decimal separator is rejected at the separator', function (): void {
    $exception = ($this->syntaxError)('1.');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(1)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a space used as a digit group separator is rejected at the second group', function (): void {
    $exception = ($this->syntaxError)('1 000');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(2)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a tab or newline used as a digit group separator is rejected', function (string $formula): void {
    $exception = ($this->syntaxError)($formula);

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(2)
        ->and(trim($exception->getMessage()))->not->toBe('');
})->with([
    'tab' => ["1\t000"],
    'newline' => ["1\n000"],
]);

test('a php open tag is rejected at the question mark', function (): void {
    $exception = ($this->syntaxError)('<?php echo 1; ?>');

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(1)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('a character outside the language is rejected at its own position', function (string $formula, int $expectedPosition): void {
    $exception = ($this->syntaxError)($formula);

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe($expectedPosition)
        ->and(trim($exception->getMessage()))->not->toBe('');
})->with([
    'at sign' => ['@', 0],
    'hash' => ['#', 0],
    'at sign after an operator' => ['1 + @', 4],
    'hash after a field reference' => ['{amount} # 1', 9],
]);

test('an oversized formula is rejected for its length before any character is scanned', function (): void {
    $max = (int) config('formulas.max_formula_length');
    $formula = str_repeat('{', $max + 1);

    $exception = ($this->syntaxError)($formula);

    expect($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and($exception->getMessage())->toContain((string) ($max + 1))
        ->and($exception->getMessage())->toContain((string) $max);
});

test('the configured maximum length is the one that is enforced', function (): void {
    config()->set('formulas.max_formula_length', 5);

    $accepted = ($this->tokenize)('12345');
    $exception = ($this->syntaxError)('123456');

    expect($accepted[0]->type)->toBe(TokenType::Number)
        ->and($accepted[0]->text)->toBe('12345')
        ->and($exception)->toBeInstanceOf(FormulaSyntaxException::class)
        ->and($exception->position)->toBe(0)
        ->and(trim($exception->getMessage()))->not->toBe('');
});

test('the formula configuration ships the documented defaults', function (): void {
    $handlers = config('formulas.function_handlers');

    expect(config('formulas.max_formula_length'))->toBe(2000)
        ->and(config('formulas.max_nesting_depth'))->toBe(32)
        ->and(config('formulas.scale'))->toBe(10)
        ->and(config('formulas.max_evaluation_steps'))->toBe(10000)
        ->and($handlers)->toBeArray()
        ->and($handlers)->toHaveCount(7)
        ->and(array_keys($handlers))->toEqualCanonicalizing(['IF', 'SUM', 'ROUND', 'CONCAT', 'DATEDIF', 'TODAY', 'TRIM']);

    foreach ($handlers as $class) {
        expect($class)->toBeString()
            ->and(is_a($class, FormulaFunctionHandler::class, true))->toBeTrue();
    }
});

test('the lexer source contains no dynamic code execution', function (): void {
    $path = app_path('Support/Formulas/FormulaLexer.php');

    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)->not->toContain('eval')
        ->and($source)->not->toContain('create_function')
        ->and($source)->not->toContain('assert(')
        ->and($source)->not->toContain('PhpOffice');
});
