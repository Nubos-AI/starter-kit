<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FieldReferenceNode;
use App\DTOs\Formulas\FormulaTypeIssue;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\StringLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaOperator;
use App\Enums\Formulas\FormulaTypeIssueCause;
use App\Enums\Formulas\FormulaValueType;

class FormulaTypeChecker
{
    public function __construct(private readonly FormulaFieldTypeMapper $mapper) {}

    /**
     * @return list<FormulaTypeIssue>
     */
    public function check(
        FormulaNode $node,
        string $objectTypeId,
        FormulaValueType $expectedResultType,
    ): array {
        $issues = [];
        $actualResultType = $this->inferType($node, $objectTypeId, $issues);

        if ($actualResultType !== null && !$this->isAssignable($actualResultType, $expectedResultType, null)) {
            $issues[] = new FormulaTypeIssue(
                cause: FormulaTypeIssueCause::ResultTypeMismatch,
                position: $this->positionOf($node),
                expectedType: $expectedResultType,
                actualType: $actualResultType,
            );
        }

        return $issues;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferType(FormulaNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        return match (true) {
            $node instanceof NumberLiteralNode => FormulaValueType::Number,
            $node instanceof StringLiteralNode => FormulaValueType::Text,
            $node instanceof BooleanLiteralNode => FormulaValueType::Boolean,
            $node instanceof FieldReferenceNode => $this->inferFieldReference($node, $objectTypeId, $issues),
            $node instanceof UnaryOperationNode => $this->inferUnaryOperation($node, $objectTypeId, $issues),
            $node instanceof BinaryOperationNode => $this->inferBinaryOperation($node, $objectTypeId, $issues),
            $node instanceof FunctionCallNode => $this->inferFunctionCall($node, $objectTypeId, $issues),
            default => null,
        };
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferFieldReference(FieldReferenceNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        $field = $this->mapper->resolveField($objectTypeId, $node->key);

        if ($field === null) {
            return $this->rejectFieldReference($node, FormulaTypeIssueCause::UnknownFieldReference, $issues);
        }

        if ($field->is_encrypted) {
            return $this->rejectFieldReference($node, FormulaTypeIssueCause::EncryptedFieldReference, $issues);
        }

        $valueType = $this->mapper->map($field);

        if ($valueType === null) {
            return $this->rejectFieldReference(
                $node,
                $field->field_type === FieldType::Computed
                    ? FormulaTypeIssueCause::UnconfiguredResultType
                    : FormulaTypeIssueCause::UnsupportedFieldType,
                $issues,
            );
        }

        return $valueType;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function rejectFieldReference(FieldReferenceNode $node, FormulaTypeIssueCause $cause, array &$issues): null
    {
        $issues[] = new FormulaTypeIssue(
            cause: $cause,
            position: $node->position,
            fieldKey: $node->key,
        );

        return null;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferUnaryOperation(UnaryOperationNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        $operand = $this->inferType($node->operand, $objectTypeId, $issues);

        if ($operand === null) {
            return null;
        }

        if ($operand !== FormulaValueType::Number) {
            return $this->rejectOperand($node->operand, FormulaValueType::Number, $operand, $issues);
        }

        return FormulaValueType::Number;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferBinaryOperation(BinaryOperationNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        $left = $this->inferType($node->left, $objectTypeId, $issues);
        $right = $this->inferType($node->right, $objectTypeId, $issues);

        if ($left === null || $right === null) {
            return null;
        }

        if ($node->operator === FormulaOperator::Ampersand) {
            return $this->inferConcatenation($node, $left, $right, $issues);
        }

        if ($this->isArithmetic($node->operator)) {
            if ($left !== FormulaValueType::Number) {
                return $this->rejectOperand($node->left, FormulaValueType::Number, $left, $issues);
            }

            if ($right !== FormulaValueType::Number) {
                return $this->rejectOperand($node->right, FormulaValueType::Number, $right, $issues);
            }

            return FormulaValueType::Number;
        }

        if ($left !== $right) {
            return $this->rejectOperand($node->right, $left, $right, $issues);
        }

        return FormulaValueType::Boolean;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferConcatenation(
        BinaryOperationNode $node,
        FormulaValueType $left,
        FormulaValueType $right,
        array &$issues,
    ): ?FormulaValueType {
        if (!$this->isAssignable($left, FormulaValueType::Text, FormulaFunction::Concat)) {
            return $this->rejectOperand($node->left, FormulaValueType::Text, $left, $issues);
        }

        if (!$this->isAssignable($right, FormulaValueType::Text, FormulaFunction::Concat)) {
            return $this->rejectOperand($node->right, FormulaValueType::Text, $right, $issues);
        }

        return FormulaValueType::Text;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function rejectOperand(
        FormulaNode $operand,
        FormulaValueType $expectedType,
        FormulaValueType $actualType,
        array &$issues,
    ): null {
        $issues[] = new FormulaTypeIssue(
            cause: FormulaTypeIssueCause::OperandTypeMismatch,
            position: $this->positionOf($operand),
            expectedType: $expectedType,
            actualType: $actualType,
        );

        return null;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferFunctionCall(FunctionCallNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        if ($node->function === FormulaFunction::IfThenElse) {
            return $this->inferConditional($node, $objectTypeId, $issues);
        }

        $signature = $node->function->signature();
        $checksArguments = $signature->acceptsArgumentCount(count($node->arguments));
        $hasFailed = false;

        foreach ($node->arguments as $index => $argument) {
            $actualType = $this->inferType($argument, $objectTypeId, $issues);

            if ($actualType === null) {
                $hasFailed = true;

                continue;
            }

            if (!$checksArguments) {
                continue;
            }

            $expectedType = $signature->expectedTypeAt($index);

            if ($expectedType === null || $this->isAssignable($actualType, $expectedType, $node->function)) {
                continue;
            }

            $this->rejectArgument($argument, $node->function, $expectedType, $actualType, $issues);

            $hasFailed = true;
        }

        return $hasFailed ? null : $signature->resultType;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function rejectArgument(
        FormulaNode $argument,
        FormulaFunction $function,
        FormulaValueType $expectedType,
        FormulaValueType $actualType,
        array &$issues,
    ): null {
        $issues[] = new FormulaTypeIssue(
            cause: FormulaTypeIssueCause::ArgumentTypeMismatch,
            position: $this->positionOf($argument),
            function: $function,
            expectedType: $expectedType,
            actualType: $actualType,
        );

        return null;
    }

    /**
     * @param  list<FormulaTypeIssue>  $issues
     */
    private function inferConditional(FunctionCallNode $node, string $objectTypeId, array &$issues): ?FormulaValueType
    {
        $arguments = $node->arguments;

        if (count($arguments) !== 3) {
            return null;
        }

        [$conditionNode, $thenNode, $elseNode] = $arguments;

        $condition = $this->inferType($conditionNode, $objectTypeId, $issues);
        $then = $this->inferType($thenNode, $objectTypeId, $issues);
        $else = $this->inferType($elseNode, $objectTypeId, $issues);

        $hasFailed = $condition === null || $then === null || $else === null;

        if ($condition !== null && $condition !== FormulaValueType::Boolean) {
            $this->rejectArgument(
                $conditionNode,
                FormulaFunction::IfThenElse,
                FormulaValueType::Boolean,
                $condition,
                $issues,
            );

            $hasFailed = true;
        }

        if ($then !== null && $else !== null && $then !== $else) {
            $issues[] = new FormulaTypeIssue(
                cause: FormulaTypeIssueCause::BranchTypeMismatch,
                position: $this->positionOf($elseNode),
                function: FormulaFunction::IfThenElse,
                expectedType: $then,
                actualType: $else,
            );

            $hasFailed = true;
        }

        return $hasFailed ? null : $then;
    }

    private function isAssignable(FormulaValueType $actual, FormulaValueType $expected, ?FormulaFunction $context): bool
    {
        if ($actual === $expected) {
            return true;
        }

        return $actual === FormulaValueType::Number
            && $expected === FormulaValueType::Text
            && $context === FormulaFunction::Concat;
    }

    private function isArithmetic(FormulaOperator $operator): bool
    {
        return match ($operator) {
            FormulaOperator::Plus,
            FormulaOperator::Minus,
            FormulaOperator::Asterisk,
            FormulaOperator::Slash => true,
            default => false,
        };
    }

    private function positionOf(FormulaNode $node): int
    {
        return match (true) {
            $node instanceof BinaryOperationNode,
            $node instanceof BooleanLiteralNode,
            $node instanceof FieldReferenceNode,
            $node instanceof FunctionCallNode,
            $node instanceof NumberLiteralNode,
            $node instanceof StringLiteralNode,
            $node instanceof UnaryOperationNode => $node->position,
            default => 0,
        };
    }
}
