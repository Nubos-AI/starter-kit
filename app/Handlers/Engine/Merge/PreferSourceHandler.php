<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\DTOs\Engine\MergeValueContext;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;
use App\Handlers\Engine\Merge\Abstracts\SideMergeStrategyHandler;

class PreferSourceHandler extends SideMergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::PreferSource;
    }

    protected function winningSide(MergeValueContext $context): MergeValueOrigin
    {
        return MergeValueOrigin::Source;
    }
}
