<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\DTOs\Engine\MergeValueContext;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;
use App\Handlers\Engine\Merge\Abstracts\SideMergeStrategyHandler;

class PreferNonEmptyHandler extends SideMergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::PreferNonEmpty;
    }

    protected function winningSide(MergeValueContext $context): MergeValueOrigin
    {
        return $context->targetIsEmpty() && !$context->sourceIsEmpty()
            ? MergeValueOrigin::Source
            : MergeValueOrigin::Target;
    }
}
