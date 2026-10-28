<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;

class ConcatenateHandler implements MergeStrategyHandler
{
    private string $separator = "\n\n";

    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::Concatenate;
    }

    public function resolve(MergeValueContext $context): MergeValueOutcome
    {
        if ($context->sourceIsEmpty()) {
            return new MergeValueOutcome($context->targetValue(), MergeValueOrigin::Target);
        }

        if ($context->targetIsEmpty()) {
            return new MergeValueOutcome($context->sourceValue(), MergeValueOrigin::Source);
        }

        $joined = (string) $context->targetValue().$this->separator.(string) $context->sourceValue();

        return new MergeValueOutcome($joined, MergeValueOrigin::Combined);
    }
}
