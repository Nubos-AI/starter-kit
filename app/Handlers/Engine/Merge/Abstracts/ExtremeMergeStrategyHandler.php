<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge\Abstracts;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeValueOrigin;

abstract class ExtremeMergeStrategyHandler implements MergeStrategyHandler
{
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

        $comparison = $this->compare($target, $source);

        return $this->sourceWins($comparison)
            ? new MergeValueOutcome($source, MergeValueOrigin::Source)
            : new MergeValueOutcome($target, MergeValueOrigin::Target);
    }

    abstract protected function sourceWins(int $comparison): bool;

    private function compare(mixed $target, mixed $source): int
    {
        if (is_numeric($target) && is_numeric($source)) {
            return $target <=> $source;
        }

        return strcmp((string) $target, (string) $source);
    }
}
