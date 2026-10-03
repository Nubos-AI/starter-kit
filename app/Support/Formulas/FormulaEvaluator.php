<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FieldReferenceNode;
use App\DTOs\Formulas\FormulaErrorValue;
use App\DTOs\Formulas\FormulaEvaluationContext;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\DTOs\Formulas\StringLiteralNode;
use App\DTOs\Formulas\UnaryOperationNode;
use App\Enums\Formulas\FormulaErrorCode;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaOperator;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaTypeException;
use InvalidArgumentException;

class FormulaEvaluator
{
    public function __construct(
        private readonly FormulaValueCoercer $coercer,
        private readonly FormulaFunctionRegistry $functions,
    ) {}

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    public function evaluateExact(
        FormulaNode $node,
        FormulaEvaluationContext $context,
    ): string|bool|FormulaErrorValue {
        $steps = 0;

        return $this->coercer->toExactValue($this->evaluateNode($node, $context, $steps));
    }

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    private function evaluateNode(
        FormulaNode $node,
        FormulaEvaluationContext $context,
        int &$steps,
    ): string|bool|FormulaErrorValue {
        $steps++;

        if ($steps > $this->maximumSteps()) {
            return new FormulaErrorValue(FormulaErrorCode::EvaluationLimitExceeded);
        }

        return match (true) {
            $node instanceof NumberLiteralNode => $node->value,
            $node instanceof StringLiteralNode => $node->value,
            $node instanceof BooleanLiteralNode => $node->value,
            $node instanceof FieldReferenceNode => $this->evaluateFieldReference($node, $context),
            $node instanceof UnaryOperationNode => $this->evaluateUnaryOperation($node, $context, $steps),
            $node instanceof BinaryOperationNode => $this->evaluateBinaryOperation($node, $context, $steps),
            $node instanceof FunctionCallNode => $this->evaluateFunctionCall($node, $context, $steps),
            default => throw new InvalidArgumentException(__('i18n.backend.support.formulas.formula_evaluator.unsupported_formula_node_type').$node::class.'.'),
        };
    }

    private function evaluateFieldReference(
        FieldReferenceNode $node,
        FormulaEvaluationContext $context,
    ): string|bool|FormulaErrorValue {
        if (!$context->hasField($node->key)) {
            return new FormulaErrorValue(FormulaErrorCode::UnknownFieldReference, $node->key);
        }

        $value = $context->valueFor($node->key);

        if ($value === null) {
            return $context->typeOf($node->key) === FormulaValueType::Text
                ? ''
                : new FormulaErrorValue(FormulaErrorCode::NotANumber, $node->key);
        }

        return $this->coercer->toWorkingValue($value, $this->scale());
    }

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    private function evaluateUnaryOperation(
        UnaryOperationNode $node,
        FormulaEvaluationContext $context,
        int &$steps,
    ): string|FormulaErrorValue {
        $operand = $this->evaluateNode($node->operand, $context, $steps);

        if ($operand instanceof FormulaErrorValue) {
            return $operand;
        }

        if ($node->operator !== FormulaOperator::Minus) {
            return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
        }

        $scale = $this->scale();
        $decimal = $this->coercer->toDecimalString($operand, $scale);

        if ($decimal instanceof FormulaErrorValue) {
            return $decimal;
        }

        return bcsub('0', $decimal, $scale);
    }

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    private function evaluateBinaryOperation(
        BinaryOperationNode $node,
        FormulaEvaluationContext $context,
        int &$steps,
    ): string|bool|FormulaErrorValue {
        $left = $this->evaluateNode($node->left, $context, $steps);

        if ($left instanceof FormulaErrorValue) {
            return $left;
        }

        $right = $this->evaluateNode($node->right, $context, $steps);

        if ($right instanceof FormulaErrorValue) {
            return $right;
        }

        if ($node->operator === FormulaOperator::Ampersand) {
            return $this->evaluateConcatenation($left, $right);
        }

        return $this->isArithmetic($node->operator)
            ? $this->evaluateArithmetic($node->operator, $left, $right)
            : $this->evaluateComparison($node->operator, $left, $right);
    }

    /**
     * @throws FormulaTypeException
     */
    private function evaluateConcatenation(string|bool $left, string|bool $right): string|bool|FormulaErrorValue
    {
        return $this->coercer->toWorkingValue(
            $this->functions->handlerFor(FormulaFunction::Concat)->evaluate([$left, $right]),
            $this->scale(),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function evaluateArithmetic(
        FormulaOperator $operator,
        string|bool $left,
        string|bool $right,
    ): string|FormulaErrorValue {
        $scale = $this->scale();
        $leftDecimal = $this->coercer->toDecimalString($left, $scale);

        if ($leftDecimal instanceof FormulaErrorValue) {
            return $leftDecimal;
        }

        $rightDecimal = $this->coercer->toDecimalString($right, $scale);

        if ($rightDecimal instanceof FormulaErrorValue) {
            return $rightDecimal;
        }

        if ($operator === FormulaOperator::Slash && bccomp($rightDecimal, '0', strlen($rightDecimal)) === 0) {
            return new FormulaErrorValue(FormulaErrorCode::DivisionByZero);
        }

        return match ($operator) {
            FormulaOperator::Plus => bcadd($leftDecimal, $rightDecimal, $scale),
            FormulaOperator::Minus => bcsub($leftDecimal, $rightDecimal, $scale),
            FormulaOperator::Asterisk => bcmul($leftDecimal, $rightDecimal, $scale),
            FormulaOperator::Slash => bcdiv($leftDecimal, $rightDecimal, $scale),
            default => throw new InvalidArgumentException(__('i18n.backend.support.formulas.formula_evaluator.unsupported_arithmetic_operator').$operator->value.'.'),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private function evaluateComparison(
        FormulaOperator $operator,
        string|bool $left,
        string|bool $right,
    ): bool|FormulaErrorValue {
        if (is_bool($left) || is_bool($right)) {
            return $this->compareBooleans($operator, $left, $right);
        }

        $scale = $this->scale();
        $leftDecimal = $this->coercer->toDecimalString($left, $scale);
        $rightDecimal = $this->coercer->toDecimalString($right, $scale);

        $comparison = is_string($leftDecimal) && is_string($rightDecimal)
            ? bccomp($leftDecimal, $rightDecimal, $scale)
            : strcmp($left, $right);

        return $this->resultOf($operator, $comparison);
    }

    private function compareBooleans(
        FormulaOperator $operator,
        string|bool $left,
        string|bool $right,
    ): bool|FormulaErrorValue {
        if (!is_bool($left) || !is_bool($right)) {
            return new FormulaErrorValue(FormulaErrorCode::TypeMismatch);
        }

        return match ($operator) {
            FormulaOperator::Equal => $left === $right,
            FormulaOperator::NotEqual => $left !== $right,
            default => new FormulaErrorValue(FormulaErrorCode::TypeMismatch),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resultOf(FormulaOperator $operator, int $comparison): bool
    {
        return match ($operator) {
            FormulaOperator::Equal => $comparison === 0,
            FormulaOperator::NotEqual => $comparison !== 0,
            FormulaOperator::LessThan => $comparison < 0,
            FormulaOperator::LessThanOrEqual => $comparison <= 0,
            FormulaOperator::GreaterThan => $comparison > 0,
            FormulaOperator::GreaterThanOrEqual => $comparison >= 0,
            default => throw new InvalidArgumentException(__('i18n.backend.support.formulas.formula_evaluator.unsupported_comparison_operator').$operator->value.'.'),
        };
    }

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    private function evaluateFunctionCall(
        FunctionCallNode $node,
        FormulaEvaluationContext $context,
        int &$steps,
    ): string|bool|FormulaErrorValue {
        if ($node->function === FormulaFunction::IfThenElse) {
            return $this->evaluateConditional($node, $context, $steps);
        }

        $arguments = [];

        foreach ($node->arguments as $argument) {
            $value = $this->evaluateNode($argument, $context, $steps);

            if ($value instanceof FormulaErrorValue) {
                return $value;
            }

            $arguments[] = $value;
        }

        return $this->coercer->toWorkingValue(
            $this->functions->handlerFor($node->function)->evaluate($arguments),
            $this->scale(),
        );
    }

    /**
     * @throws FormulaTypeException
     * @throws InvalidArgumentException
     */
    private function evaluateConditional(
        FunctionCallNode $node,
        FormulaEvaluationContext $context,
        int &$steps,
    ): string|bool|FormulaErrorValue {
        if (count($node->arguments) !== 3) {
            return new FormulaErrorValue(FormulaErrorCode::InvalidArgumentCount);
        }

        [$conditionNode, $thenNode, $elseNode] = $node->arguments;

        $condition = $this->evaluateNode($conditionNode, $context, $steps);

        if ($condition instanceof FormulaErrorValue) {
            return $condition;
        }

        $isTrue = $this->coercer->toBoolean($condition);

        if ($isTrue instanceof FormulaErrorValue) {
            return $isTrue;
        }

        return $this->evaluateNode($isTrue ? $thenNode : $elseNode, $context, $steps);
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

    private function scale(): int
    {
        return (int) config('formulas.scale');
    }

    private function maximumSteps(): int
    {
        return (int) config('formulas.max_evaluation_steps');
    }
}
