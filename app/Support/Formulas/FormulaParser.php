<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FieldReferenceNode;
use App\DTOs\Formulas\FormulaToken;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\StringLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaOperator;
use App\Enums\Formulas\TokenType;
use App\Exceptions\Formulas\FormulaSyntaxException;

class FormulaParser
{
    /**
     * @var list<FormulaToken>
     */
    private array $tokens = [];

    private int $cursor = 0;

    private int $depth = 0;

    private int $maximumDepth = 0;

    public function __construct(private readonly FormulaLexer $lexer) {}

    /**
     * @throws FormulaSyntaxException
     */
    public function parse(string $formula): FormulaNode
    {
        $this->tokens = $this->lexer->tokenize($formula);
        $this->cursor = 0;
        $this->depth = 0;
        $this->maximumDepth = (int) config('formulas.max_nesting_depth');

        $node = $this->parseExpression(0);
        $token = $this->current();

        if ($token->type !== TokenType::EndOfInput) {
            throw FormulaSyntaxException::unexpectedToken($token->text, $token->position);
        }

        return $node;
    }

    /**
     * @throws FormulaSyntaxException
     */
    private function parseExpression(int $minimumPrecedence): FormulaNode
    {
        $this->depth++;

        if ($this->depth > $this->maximumDepth) {
            throw FormulaSyntaxException::nestingTooDeep($this->maximumDepth, $this->current()->position);
        }

        $start = $this->current()->position;
        $left = $this->parsePrefix();

        while (true) {
            $operator = FormulaOperator::fromTokenType($this->current()->type);

            if ($operator === null || $operator->precedence() < $minimumPrecedence) {
                break;
            }

            $this->advance();

            $right = $this->parseExpression($operator->precedence() + ($operator->isLeftAssociative() ? 1 : 0));
            $left = new BinaryOperationNode($operator, $left, $right, $start);
        }

        $this->depth--;

        return $left;
    }

    /**
     * @throws FormulaSyntaxException
     */
    private function parsePrefix(): FormulaNode
    {
        $token = $this->current();

        if ($token->type === TokenType::EndOfInput) {
            throw $this->cursor === 0
                ? FormulaSyntaxException::emptyFormula($token->position)
                : FormulaSyntaxException::missingOperand($token->position);
        }

        if ($token->type === TokenType::LeftParenthesis) {
            return $this->parseGroup();
        }

        if ($token->type === TokenType::Identifier) {
            return $this->parseFunctionCall();
        }

        $operator = FormulaOperator::fromTokenType($token->type);
        $prefixPrecedence = $operator?->prefixPrecedence();

        if ($operator !== null && $prefixPrecedence !== null) {
            $this->advance();

            return new UnaryOperationNode($operator, $this->parseExpression($prefixPrecedence), $token->position);
        }

        $node = match ($token->type) {
            TokenType::Number => new NumberLiteralNode($token->text, $token->position),
            TokenType::Text => new StringLiteralNode($token->text, $token->position),
            TokenType::Boolean => new BooleanLiteralNode($token->text === 'TRUE', $token->position),
            TokenType::FieldReference => new FieldReferenceNode($token->text, $token->position),
            default => throw FormulaSyntaxException::unexpectedToken($token->text, $token->position),
        };

        $this->advance();

        return $node;
    }

    /**
     * @throws FormulaSyntaxException
     */
    private function parseGroup(): FormulaNode
    {
        $open = $this->current()->position;
        $this->advance();

        $node = $this->parseExpression(0);

        if ($this->current()->type !== TokenType::RightParenthesis) {
            throw FormulaSyntaxException::unclosedParenthesis($open);
        }

        $this->advance();

        return $node;
    }

    /**
     * @throws FormulaSyntaxException
     */
    private function parseFunctionCall(): FormulaNode
    {
        $name = $this->current();
        $this->advance();

        if ($this->current()->type !== TokenType::LeftParenthesis) {
            throw FormulaSyntaxException::functionCallExpected($name->text, $name->position);
        }

        $function = FormulaFunction::tryFrom($name->text);

        if ($function === null) {
            throw FormulaSyntaxException::unknownFunction($name->text, $name->position);
        }

        $open = $this->current()->position;
        $this->advance();

        $arguments = [];

        if ($this->current()->type !== TokenType::RightParenthesis) {
            $arguments[] = $this->parseExpression(0);

            while ($this->current()->type === TokenType::Semicolon) {
                $this->advance();

                $arguments[] = $this->parseExpression(0);
            }
        }

        if ($this->current()->type !== TokenType::RightParenthesis) {
            throw FormulaSyntaxException::unclosedParenthesis($open);
        }

        $this->advance();

        $signature = $function->signature();

        if (!$signature->acceptsArgumentCount(count($arguments))) {
            throw FormulaSyntaxException::argumentCountMismatch(
                $function->value,
                count($arguments),
                $signature->minArgs,
                $signature->maxArgs,
                $name->position,
            );
        }

        return new FunctionCallNode($function, $arguments, $name->position);
    }

    private function current(): FormulaToken
    {
        return $this->tokens[$this->cursor];
    }

    private function advance(): void
    {
        $this->cursor++;
    }
}
