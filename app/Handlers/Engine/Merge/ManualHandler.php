<?php

declare(strict_types=1);

namespace App\Handlers\Engine\Merge;

use App\Contracts\Engine\MergeStrategyHandler;
use App\DTOs\Engine\MergeValueContext;
use App\DTOs\Engine\MergeValueOutcome;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;

class ManualHandler implements MergeStrategyHandler
{
    public function strategy(): MergeFieldStrategy
    {
        return MergeFieldStrategy::Manual;
    }

    public function resolve(MergeValueContext $context): MergeValueOutcome
    {
        return new MergeValueOutcome(
            $context->targetValue(),
            MergeValueOrigin::Undecided,
            true,
        );
    }
}
