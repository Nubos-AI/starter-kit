<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\Enums\Engine\MergeFieldStrategy;
use App\Handlers\Engine\Merge\Abstracts\ExtremeMergeStrategyHandler;

class MinHandler extends ExtremeMergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::Min;
    }

    protected function sourceWins(int $comparison): bool
    {
        return $comparison > 0;
    }
}
