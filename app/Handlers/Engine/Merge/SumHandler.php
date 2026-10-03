<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;

class SumHandler implements MergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::Sum;
    }

    public function resolve(MergeValueContext $context): MergeValueOutcome
    {
        if ($context->sourceIsEmpty()) {
            return new MergeValueOutcome($context->targetValue(), MergeValueOrigin::Target);
        }

        if ($context->targetIsEmpty()) {
            return new MergeValueOutcome($context->sourceValue(), MergeValueOrigin::Source);
        }

        $target = $context->targetValue();
        $source = $context->sourceValue();

        if (!is_numeric($target) || !is_numeric($source)) {
            return new MergeValueOutcome($target, MergeValueOrigin::Target);
        }

        $sum = $target + $source;

        return new MergeValueOutcome(
            is_int($target) && is_int($source) ? (int) $sum : (float) $sum,
            MergeValueOrigin::Combined,
        );
    }
}
