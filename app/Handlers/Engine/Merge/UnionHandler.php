<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;

class UnionHandler implements MergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::Union;
    }

    public function resolve(MergeValueContext $context): MergeValueOutcome
    {
        if ($context->sourceIsEmpty()) {
            return new MergeValueOutcome($context->targetValue(), MergeValueOrigin::Target);
        }

        if ($context->targetIsEmpty()) {
            return new MergeValueOutcome($context->sourceValue(), MergeValueOrigin::Source);
        }

        $merged = array_values(array_unique([
            ...$this->entries($context->targetValue()),
            ...$this->entries($context->sourceValue()),
        ], SORT_REGULAR));

        return new MergeValueOutcome($merged, MergeValueOrigin::Combined);
    }

    /**
     * @return list<mixed>
     */
    private function entries(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }
}
