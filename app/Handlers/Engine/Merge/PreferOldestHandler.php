<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\DTOs\Engine\MergeValueContext;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;
use App\Handlers\Engine\Merge\Abstracts\SideMergeStrategyHandler;

class PreferOldestHandler extends SideMergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::PreferOldest;
    }

    protected function winningSide(MergeValueContext $context): MergeValueOrigin
    {
        $target = $context->target->updated_at;
        $source = $context->source->updated_at;

        if ($target === null || $source === null) {
            return MergeValueOrigin::Target;
        }

        return $source->lessThan($target) ? MergeValueOrigin::Source : MergeValueOrigin::Target;
    }
}
